<?php

declare(strict_types=1);

namespace Sentry\Bref\Symfony\DependencyInjection;

use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Lets the Bref runtime process get the hub of the Sentry bundle: see `SymfonyBridge`.
 */
class PublicHubPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(HubInterface::class)) {
            $container->getDefinition(HubInterface::class)->setPublic(true);
        }
    }
}
