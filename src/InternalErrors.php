<?php declare(strict_types=1);

namespace Sentry\Bref;

use Throwable;

/**
 * Tracing must never break the application it observes: errors in this package are
 * caught and logged to stderr (i.e. CloudWatch on Lambda) instead.
 *
 * @internal
 */
class InternalErrors
{
    public static function log(Throwable $e): void
    {
        error_log(sprintf(
            'Sentry Bref integration error: %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));
    }
}
