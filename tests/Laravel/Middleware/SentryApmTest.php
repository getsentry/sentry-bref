<?php

declare(strict_types=1);

namespace Sentry\Bref\Test\Laravel\Middleware;

use PHPUnit\Framework\TestCase;
use Sentry\Bref\BrefSpans;
use Sentry\Bref\CurrentScope;
use Sentry\Bref\Laravel\Middleware\SentryApm;
use Sentry\Bref\Test\Support\TestSdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group laravel
 */
class SentryApmTest extends TestCase
{
    private const SPANS_FILE = '/tmp/bref-sentry-spans.json';

    protected function setUp(): void
    {
        if (file_exists(self::SPANS_FILE)) {
            unlink(self::SPANS_FILE);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists(self::SPANS_FILE)) {
            unlink(self::SPANS_FILE);
        }
    }

    public function testLoadsBrefSpansIntoTheCurrentTransaction(): void
    {
        TestSdk::init();
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();
        CurrentScope::setSpan($transaction);
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', microtime(true) - 1, microtime(true));

        $response = new Response();
        $result = (new SentryApm())->handle(new Request(), static fn () => $response);

        $this->assertSame($response, $result);
        $ops = array_map(static fn (Span $span) => $span->getOp(), $transaction->getSpanRecorder()?->getSpans() ?? []);
        $this->assertContains('lambda.cold-start', $ops);
    }

    public function testRepairsSpansEndingBeforeTheyStart(): void
    {
        TestSdk::init();
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();
        CurrentScope::setSpan($transaction);
        // Like sentry-laravel's `app.bootstrap` span with Octane: Laravel booted before the request
        $bootstrap = $transaction->startChild(SpanContext::make()
            ->setOp('app.bootstrap')
            ->setStartTimestamp($transaction->getStartTimestamp())
            ->setEndTimestamp($transaction->getStartTimestamp() - 0.05));

        (new SentryApm())->handle(new Request(), static fn () => new Response());

        $this->assertSame($bootstrap->getEndTimestamp(), $bootstrap->getStartTimestamp());
    }
}
