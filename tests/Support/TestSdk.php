<?php

declare(strict_types=1);

namespace Sentry\Bref\Test\Support;

use Sentry\ClientInterface;
use Sentry\SentrySdk;

/**
 * Resets the SDK, with sentry/sentry 4 and 5.
 */
final class TestSdk
{
    public static function init(?ClientInterface $client = null): void
    {
        if (method_exists(SentrySdk::class, 'getIsolationScope')) {
            SentrySdk::init($client);
        } else {
            $hub = SentrySdk::init();
            if ($client !== null) {
                $hub->bindClient($client);
            }
        }
    }
}
