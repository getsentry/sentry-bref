<?php

declare(strict_types=1);

namespace Sentry\Bref\Symfony;

use Sentry\Bref\Symfony\DependencyInjection\BrefSentryExtension;
use Sentry\Bref\Symfony\DependencyInjection\PublicHubPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class BrefSentryBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new PublicHubPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new BrefSentryExtension();
    }
}
