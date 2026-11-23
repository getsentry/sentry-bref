<?php

declare(strict_types=1);

namespace Sentry\Bref;

use Bref\Bref;
use Bref\LaravelBridge\HandlerResolver;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;

/**
 * Gives the SDK the client configured in the application, in the Bref runtime process.
 *
 * With a Bref bridge (e.g. bref/symfony-bridge), Bref's container gives access to the application's
 * container. Some frameworks only bind their client to the SDK when the hub service is
 * created (sentry-symfony): getting the hub from there binds it.
 *
 * @internal
 */
class FrameworkHub
{
    public static function load(): void
    {
        try {
            if (SentrySdk::getCurrentHub()->getClient() !== null) {
                return;
            }

            $container = Bref::getContainer();
            // sentry-laravel binds its client when Laravel boots, and laravel-bridge's container
            // would boot another instance of the application (e.g. next to Octane's)
            if ($container instanceof HandlerResolver) {
                return;
            }
            if ($container->has(HubInterface::class)) {
                $container->get(HubInterface::class);
            }
        } catch (\Throwable $e) {
            InternalErrors::log($e);
        }
    }
}
