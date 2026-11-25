<?php

declare(strict_types=1);

namespace Sentry\Bref\Test\Laravel;

use Aws\MockHandler;
use Aws\Result;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueServiceProvider;
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
        Container::setInstance(null);
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

    public function testTracesTheSqsClientOfQueues(): void
    {
        $app = $this->createApplication([
            'queue.default' => 'sqs',
            'queue.connections.sqs' => [
                'driver' => 'sqs',
                'prefix' => 'https://sqs.eu-west-1.amazonaws.com/123456789012',
                'queue' => 'jobs',
                'region' => 'eu-west-1',
                'credentials' => false,
                'handler' => new MockHandler([new Result(['MessageId' => 'message-id'])]),
            ],
        ]);
        $transaction = $this->startTransaction();

        $app->make(QueueFactory::class)->connection()->push('App\\Jobs\\TestJob');

        $this->assertSame(['SQS.SendMessage'], $this->spanDescriptions($transaction));
    }

    public function testTracesTheSqsClientOfQueuesWithOctane(): void
    {
        $app = $this->createApplication([
            'queue.default' => 'sqs',
            'queue.connections.sqs' => [
                'driver' => 'sqs',
                'prefix' => 'https://sqs.eu-west-1.amazonaws.com/123456789012',
                'queue' => 'jobs',
                'region' => 'eu-west-1',
                'credentials' => false,
                'handler' => new MockHandler([new Result(['MessageId' => 'message-id'])]),
            ],
        ]);
        // Octane handles each request with a copy of the application
        $sandbox = clone $app;
        Container::setInstance($sandbox);
        $transaction = $this->startTransaction();

        $sandbox->make(QueueFactory::class)->connection()->push('App\\Jobs\\TestJob');

        $this->assertSame(['SQS.SendMessage'], $this->spanDescriptions($transaction));
    }

    public function testTracesTheS3ClientOfDisks(): void
    {
        $app = $this->createApplication([
            'filesystems.disks.s3' => [
                'driver' => 's3',
                'bucket' => 'my-bucket',
                'region' => 'eu-west-1',
                'credentials' => false,
                'handler' => new MockHandler([new Result([])]),
            ],
        ]);
        $transaction = $this->startTransaction();

        $app->make(FilesystemFactory::class)->disk('s3')->put('file.txt', 'content');

        $this->assertSame(['S3.PutObject'], $this->spanDescriptions($transaction));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createApplication(array $config): Application
    {
        $app = new Application();
        Facade::setFacadeApplication($app);
        $repository = new Repository();
        foreach ($config as $key => $value) {
            $repository->set($key, $value);
        }
        $app->instance('config', $repository);
        $app->register(FilesystemServiceProvider::class);
        $app->register(QueueServiceProvider::class);
        $app->register(BrefSentryServiceProvider::class);
        $app->boot();

        return $app;
    }

    private function startTransaction(): Transaction
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();
        SentrySdk::init()->setSpan($transaction);

        return $transaction;
    }

    /**
     * @return list<string|null>
     */
    private function spanDescriptions(Transaction $transaction): array
    {
        $spans = array_filter($transaction->getSpanRecorder()?->getSpans() ?? [], static fn (Span $span) => $span !== $transaction);

        return array_values(array_map(static fn (Span $span) => $span->getDescription(), $spans));
    }
}
