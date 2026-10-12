<?php

declare(strict_types=1);

namespace Sentry\Bref\Symfony\DependencyInjection;

use Sentry\Bref\Symfony\EventSubscriber;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class BrefSentryExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->autowire(EventSubscriber::class)
            ->addTag('kernel.event_subscriber');
    }
}
