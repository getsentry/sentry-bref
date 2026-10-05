<?php declare(strict_types=1);

namespace Sentry\Bref\Test\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Sentry\Bref\Symfony\DependencyInjection\BrefSentryExtension;
use Sentry\Bref\Symfony\EventSubscriber;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class BrefSentryExtensionTest extends TestCase
{
    public function testRegistersTheEventSubscriber(): void
    {
        $container = new ContainerBuilder;
        (new BrefSentryExtension)->load([], $container);

        $definition = $container->getDefinition(EventSubscriber::class);
        $this->assertTrue($definition->isAutowired());
        $this->assertTrue($definition->hasTag('kernel.event_subscriber'));
    }
}
