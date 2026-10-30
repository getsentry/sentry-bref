<?php

declare(strict_types=1);

namespace Sentry\Bref;

use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;

/**
 * The current span and runtime context, with sentry/sentry 4 (hub) and 5 (isolation scope).
 *
 * @internal
 */
final class CurrentScope
{
    /**
     * With sentry/sentry 4: the hub whose scope `startContext()` pushed.
     */
    private static ?HubInterface $contextHub = null;

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

    /**
     * Isolates the scope, logs and metrics of what runs until `endContext()`.
     */
    public static function startContext(): void
    {
        if (self::isV5()) {
            // A new isolation scope starts empty: the current one holds what the application set when booting
            SentrySdk::startContext(clone SentrySdk::getIsolationScope());

            return;
        }

        // The same hub, not a new one: the hub is often kept since the application booted
        // (Laravel's container, Monolog handlers…), the events it sends must be part of the context
        $hub = SentrySdk::getCurrentHub();
        SentrySdk::startContext($hub);
        $hub->pushScope();
        self::$contextHub = $hub;
    }

    /**
     * Also sends the logs and metrics buffered since `startContext()`.
     */
    public static function endContext(): void
    {
        $hub = self::$contextHub;
        self::$contextHub = null;
        $hub?->popScope();
        SentrySdk::endContext();
    }

    /**
     * Applies to the events of every scope (and hub with sentry/sentry 4).
     *
     * @param callable(\Sentry\Event): ?\Sentry\Event $eventProcessor
     */
    public static function addGlobalEventProcessor(callable $eventProcessor): void
    {
        if (self::isV5()) {
            SentrySdk::getGlobalScope()->addEventProcessor($eventProcessor);
        } else {
            Scope::addGlobalEventProcessor($eventProcessor);
        }
    }

    private static function isV5(): bool
    {
        return method_exists(SentrySdk::class, 'getIsolationScope');
    }
}
