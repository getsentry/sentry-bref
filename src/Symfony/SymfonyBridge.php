<?php

declare(strict_types=1);

namespace Sentry\Bref\Symfony;

use Bref\Bref;
use Sentry\Bref\InternalErrors;
use Sentry\State\HubInterface;

class SymfonyBridge
{
    /**
     * With bref/symfony-bridge, Bref's container gives access to Symfony's container: it boots
     * Symfony once. Getting the hub from there gives the client configured in Symfony to the SDK.
     */
    public static function bootSymfony(): void
    {
        try {
            $container = Bref::getContainer();
            if ($container->has(HubInterface::class)) {
                $container->get(HubInterface::class);
            }
        } catch (\Throwable $e) {
            InternalErrors::log($e);
        }
    }
}
