<?php

declare(strict_types=1);

namespace Sentry\Bref\Test\Laravel;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Sentry\Bref\BrefSpans;
use Sentry\Bref\Laravel\BrefSentryServiceProvider;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

/**
 * @group laravel
 */
class BrefSentryServiceProviderTest extends TestCase
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
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        SentrySdk::init();
    }

    public function testAddsBrefSpansToTheTransactionOfTheJob(): void
    {
        $app = new Application();
        // Laravel's own listeners use facades
        Facade::setFacadeApplication($app);
        $app->register(BrefSentryServiceProvider::class);
        $app->boot();
        // sentry-laravel started the job's transaction
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();
        SentrySdk::init()->setSpan($transaction);
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', microtime(true) - 1, microtime(true));

        $app['events']->dispatch(new JobProcessing('sqs', $this->createMock(Job::class)));

        $ops = array_map(static fn (Span $span) => $span->getOp(), $transaction->getSpanRecorder()?->getSpans() ?? []);
        $this->assertContains('lambda.cold-start', $ops);
    }
}
