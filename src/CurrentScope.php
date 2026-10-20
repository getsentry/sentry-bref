<?php

declare(strict_types=1);

namespace Sentry\Bref;

use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;

/**
 * The current span, with sentry/sentry 4 (hub) and 5 (isolation scope).
 *
 * @internal
 */
final class CurrentScope
{
    public static function getSpan(): ?Span
    {
        return self::isV5() ? SentrySdk::getIsolationScope()->getSpan() : SentrySdk::getCurrentHub()->getSpan();
    }

    public static function setSpan(?Span $span): void
    {
        if (self::isV5()) {
            SentrySdk::getIsolationScope()->setSpan($span);
        } else {
            SentrySdk::getCurrentHub()->setSpan($span);
        }
    }

    public static function getTransaction(): ?Transaction
    {
        return self::isV5() ? SentrySdk::getIsolationScope()->getTransaction() : SentrySdk::getCurrentHub()->getTransaction();
    }

    public static function getOptions(): ?Options
    {
        return self::isV5() ? SentrySdk::getClient()->getOptions() : SentrySdk::getCurrentHub()->getClient()?->getOptions();
    }

    private static function isV5(): bool
    {
        return method_exists(SentrySdk::class, 'getIsolationScope');
    }
}
