<?php

declare(strict_types=1);

namespace Sentry\Bref\Test;

use Bref\Bref;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sentry\Bref\FrameworkHub;
use Sentry\Bref\InternalErrors;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;

class FrameworkHubTest extends TestCase
{
    protected function setUp(): void
    {
        SentrySdk::init();
    }

    protected function tearDown(): void
    {
        Bref::reset();
    }

    public function testDoesNothingWhenTheSdkAlreadyHasAClient(): void
    {
        SentrySdk::getCurrentHub()->bindClient(ClientBuilder::create()->getClient());
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('has');
        Bref::setContainer(static fn () => $container);

        FrameworkHub::load();
    }

    public function testGetsTheHubFromTheContainerOfBref(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(HubInterface::class)->willReturn(true);
        $container->expects($this->once())->method('get')->with(HubInterface::class);
        Bref::setContainer(static fn () => $container);

        FrameworkHub::load();
    }

    public function testDoesNothingWhenTheContainerHasNoHub(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(HubInterface::class)->willReturn(false);
        $container->expects($this->never())->method('get');
        Bref::setContainer(static fn () => $container);

        FrameworkHub::load();
    }

    public function testAFailureToGetTheHubIsLogged(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willThrowException(new \RuntimeException('Cannot boot'));
        Bref::setContainer(static fn () => $container);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Sentry Bref integration error: Cannot boot');
        InternalErrors::setLogger($logger);

        try {
            FrameworkHub::load();
        } finally {
            InternalErrors::setLogger(null);
        }
    }
}
