<?php

declare(strict_types=1);

namespace Sentry\Bref\Test\Symfony;

use Bref\Bref;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sentry\Bref\InternalErrors;
use Sentry\Bref\Symfony\SymfonyBridge;
use Sentry\State\HubInterface;

class SymfonyBridgeTest extends TestCase
{
    protected function tearDown(): void
    {
        Bref::reset();
    }

    public function testGetsTheHubFromTheContainerOfBref(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(HubInterface::class)->willReturn(true);
        $container->expects($this->once())->method('get')->with(HubInterface::class);
        Bref::setContainer(static fn () => $container);

        SymfonyBridge::bootSymfony();
    }

    public function testDoesNothingWhenTheContainerHasNoHub(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(HubInterface::class)->willReturn(false);
        $container->expects($this->never())->method('get');
        Bref::setContainer(static fn () => $container);

        SymfonyBridge::bootSymfony();
    }

    public function testAFailureToBootDoesNotPreventReportingTheError(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willThrowException(new \RuntimeException('Cannot boot'));
        Bref::setContainer(static fn () => $container);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Sentry Bref integration error: Cannot boot');
        InternalErrors::setLogger($logger);

        try {
            SymfonyBridge::bootSymfony();
        } finally {
            InternalErrors::setLogger(null);
        }
    }
}
