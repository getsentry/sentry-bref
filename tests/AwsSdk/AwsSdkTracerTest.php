<?php declare(strict_types=1);

namespace Sentry\Bref\Test\AwsSdk;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Promise\Utils;
use PHPUnit\Framework\TestCase;
use Sentry\Bref\AwsSdk\AwsSdkTracer;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

class AwsSdkTracerTest extends TestCase
{
    private const QUEUE_URL = 'https://sqs.eu-west-1.amazonaws.com/123456789012/queue';

    public function testTracesAwsSdkCallsAsChildSpans(): void
    {
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);

        $client = $this->createSqsClient(fn () => new Result(['QueueUrls' => [self::QUEUE_URL]]));
        AwsSdkTracer::trace($client);
        $client->listQueues();

        $spans = $this->childSpans($transaction);
        $this->assertCount(1, $spans);
        $this->assertSame('aws.sdk.sqs', $spans[0]->getOp());
        $this->assertSame('SqsClient ListQueues', $spans[0]->getDescription());
        $this->assertSame([
            'aws.sdk.client' => 'SqsClient',
            'aws.sdk.command' => 'ListQueues',
            'aws.sdk.region' => 'eu-west-1',
            'aws.sdk.endpoint' => (string) $client->getEndpoint(),
        ], $spans[0]->getData());
        $this->assertNotNull($spans[0]->getEndTimestamp());
        // The current span is restored after the call
        $this->assertSame($transaction, $hub->getSpan());
    }

    public function testDoesNotTraceWithoutACurrentSpan(): void
    {
        $hub = SentrySdk::init();

        $client = $this->createSqsClient(fn () => new Result(['QueueUrls' => [self::QUEUE_URL]]));
        AwsSdkTracer::trace($client);
        $result = $client->listQueues();

        $this->assertSame([self::QUEUE_URL], $result->get('QueueUrls'));
        $this->assertNull($hub->getSpan());
    }

    public function testFinishesTheSpanWhenTheCallFails(): void
    {
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);

        $client = $this->createSqsClient(fn (CommandInterface $command) => new SqsException('Access denied', $command));
        AwsSdkTracer::trace($client);
        try {
            $client->listQueues();
            $this->fail('The AWS SDK exception should be thrown');
        } catch (SqsException $e) {
            $this->assertSame('Access denied', $e->getMessage());
        }

        $spans = $this->childSpans($transaction);
        $this->assertCount(1, $spans);
        $this->assertNotNull($spans[0]->getEndTimestamp());
        // The current span is restored after the call
        $this->assertSame($transaction, $hub->getSpan());
    }

    public function testFailedCallsAreMarkedAsErrors(): void
    {
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);

        $client = $this->createSqsClient(fn (CommandInterface $command) => new SqsException('Access denied', $command));
        AwsSdkTracer::trace($client);
        try {
            $client->listQueues();
        } catch (SqsException) {
        }

        $spans = $this->childSpans($transaction);
        $this->assertCount(1, $spans);
        $this->assertNotNull($spans[0]->getStatus());
        $this->assertNotEquals(SpanStatus::ok(), $spans[0]->getStatus());
    }

    public function testConcurrentCallsAreSiblingSpans(): void
    {
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);

        $client = $this->createSqsClient(
            fn () => new Result(['QueueUrls' => [self::QUEUE_URL]]),
            fn () => new Result(['QueueUrl' => self::QUEUE_URL]),
        );
        AwsSdkTracer::trace($client);
        Utils::all([
            $client->listQueuesAsync(),
            $client->getQueueUrlAsync(['QueueName' => 'queue']),
        ])->wait();

        $spans = $this->childSpans($transaction);
        $this->assertCount(2, $spans);
        $this->assertEquals($transaction->getSpanId(), $spans[0]->getParentSpanId());
        $this->assertEquals($transaction->getSpanId(), $spans[1]->getParentSpanId());
        // The current span is restored after the calls
        $this->assertSame($transaction, $hub->getSpan());
    }

    private function createSqsClient(callable ...$mockedResults): SqsClient
    {
        return new SqsClient([
            'region' => 'eu-west-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => new MockHandler($mockedResults),
        ]);
    }

    /**
     * @return Span[]
     */
    private function childSpans(Transaction $transaction): array
    {
        $spans = $transaction->getSpanRecorder()?->getSpans() ?? [];

        return array_values(array_filter($spans, fn (Span $span) => $span !== $transaction));
    }
}
