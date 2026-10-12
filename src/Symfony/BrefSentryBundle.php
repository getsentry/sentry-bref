<?php

declare(strict_types=1);

namespace Sentry\Bref\Symfony;

use Sentry\Bref\Symfony\DependencyInjection\BrefSentryExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class BrefSentryBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new BrefSentryExtension();
    }
}
