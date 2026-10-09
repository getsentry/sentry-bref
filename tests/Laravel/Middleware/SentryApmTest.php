<?php declare(strict_types=1);

namespace Sentry\Bref\Test\Laravel\Middleware;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Sentry\Bref\BrefSpans;
use Sentry\Bref\Laravel\Middleware\SentryApm;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', microtime(true) - 1, microtime(true));
        $app = new Application;
        $app->instance(HubInterface::class, $hub);

        $response = new Response;
        $result = (new SentryApm($app))->handle(new Request, fn () => $response);

        $this->assertSame($response, $result);
        $ops = array_map(fn (Span $span) => $span->getOp(), $transaction->getSpanRecorder()?->getSpans() ?? []);
        $this->assertContains('lambda.cold-start', $ops);
    }

    public function testRepairsSpansEndingBeforeTheyStart(): void
    {
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);
        // Like sentry-laravel's `app.bootstrap` span with Octane: Laravel booted before the request
        $bootstrap = $transaction->startChild(SpanContext::make()
            ->setOp('app.bootstrap')
            ->setStartTimestamp($transaction->getStartTimestamp())
            ->setEndTimestamp($transaction->getStartTimestamp() - 0.05));
        $app = new Application;
        $app->instance(HubInterface::class, $hub);

        (new SentryApm($app))->handle(new Request, fn () => new Response);

        $this->assertSame($bootstrap->getEndTimestamp(), $bootstrap->getStartTimestamp());
    }

    public function testDoesNothingWhenSentryIsNotConfigured(): void
    {
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', microtime(true) - 1, microtime(true));

        // The Sentry Laravel SDK is not installed or not configured
        (new SentryApm(new Application))->handle(new Request, fn () => new Response);

        $this->assertCount(1, $transaction->getSpanRecorder()?->getSpans() ?? []);
    }
}
