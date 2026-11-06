<?php

declare(strict_types=1);

namespace Sentry\Bref\Test\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Sentry\Bref\Symfony\DependencyInjection\PublicHubPass;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @group symfony
 */
class PublicHubPassTest extends TestCase
{
    public function testMakesTheHubOfTheSentryBundlePublic(): void
    {
        $container = new ContainerBuilder();
        $container->register(HubInterface::class)->setPublic(false);

        (new PublicHubPass())->process($container);

        $this->assertTrue($container->getDefinition(HubInterface::class)->isPublic());
    }

    public function testDoesNothingWithoutTheSentryBundle(): void
    {
        $container = new ContainerBuilder();

        (new PublicHubPass())->process($container);

        $this->assertFalse($container->has(HubInterface::class));
    }
}
