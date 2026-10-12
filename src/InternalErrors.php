<?php

declare(strict_types=1);

namespace Sentry\Bref;

use Bref\Logger\StderrLogger;
use Psr\Log\LoggerInterface;

/**
 * Tracing must never break the application it observes: errors in this package are
 * caught and logged to stderr (i.e. CloudWatch on Lambda) instead, in Bref's log format
 * so that they can be filtered by level and exception class.
 *
 * @internal
 */
class InternalErrors
{
    private static ?LoggerInterface $logger = null;

    public static function log(\Throwable $e): void
    {
        self::$logger ??= new StderrLogger();
        self::$logger->error('Sentry Bref integration error: ' . $e->getMessage(), ['exception' => $e]);
    }

    /**
     * For tests.
     */
    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }
}
