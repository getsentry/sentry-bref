<?php declare(strict_types=1);

namespace Sentry\Bref\Test\AwsSdk;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Sentry\Bref\AwsSdk\AwsSdkTracer;
use Sentry\ClientBuilder;
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

        $client = $this->createSqsClient(fn () => new Result([
            'QueueUrls' => [self::QUEUE_URL],
            '@metadata' => ['headers' => ['x-amzn-requestid' => 'f5c1b5c0-request-id']],
        ]));
        AwsSdkTracer::trace($client);
        $client->listQueues();

        $spans = $this->childSpans($transaction);
        $this->assertCount(1, $spans);
        $this->assertSame('http.client', $spans[0]->getOp());
        $this->assertSame('auto.http.aws_sdk', $spans[0]->getOrigin());
        $this->assertSame('SQS.ListQueues', $spans[0]->getDescription());
        $this->assertSame([
            'rpc.system.name' => 'aws-api',
            'rpc.service' => 'SQS',
            'rpc.method' => 'ListQueues',
            'cloud.region' => 'eu-west-1',
            'http.request.method' => 'POST',
            'server.address' => 'sqs.eu-west-1.amazonaws.com',
            'url.domain' => 'sqs.eu-west-1.amazonaws.com',
            'http.response.status_code' => 200,
            'aws.request_id' => 'f5c1b5c0-request-id',
        ], $spans[0]->getData());
        $this->assertEquals(SpanStatus::ok(), $spans[0]->getStatus());
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

    public function testRecordsTheFullUrlWhenDataCollectionAllowsIt(): void
    {
        $hub = SentrySdk::init();
        // Any `data_collection` value opts into the SDK's data collection rules
        $hub->bindClient(ClientBuilder::create(['data_collection' => []])->getClient());
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);

        $client = $this->createSqsClient(fn () => new Result(['QueueUrls' => [self::QUEUE_URL]]));
        AwsSdkTracer::trace($client);
        $client->listQueues();

        $spans = $this->childSpans($transaction);
        $this->assertCount(1, $spans);
        $this->assertSame('https://sqs.eu-west-1.amazonaws.com/', $spans[0]->getData()['url.full']);
    }

    public function testRecordsTheResponseOfFailedCalls(): void
    {
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);

        $client = $this->createSqsClient(fn (CommandInterface $command) => new SqsException('Access denied', $command, [
            'request' => new Request('POST', 'https://sqs.eu-west-1.amazonaws.com/'),
            'response' => new Response(403, ['x-amz-id-2' => 'extended-request-id']),
            'request_id' => 'f5c1b5c0-request-id',
        ]));
        AwsSdkTracer::trace($client);
        try {
            $client->listQueues();
        } catch (SqsException) {
        }

        $spans = $this->childSpans($transaction);
        $this->assertCount(1, $spans);
        $this->assertEquals(SpanStatus::permissionDenied(), $spans[0]->getStatus());
        $this->assertSame('sqs.eu-west-1.amazonaws.com', $spans[0]->getData()['server.address']);
        $this->assertSame(403, $spans[0]->getData()['http.response.status_code']);
        $this->assertSame('f5c1b5c0-request-id', $spans[0]->getData()['aws.request_id']);
        $this->assertSame('extended-request-id', $spans[0]->getData()['aws.extended_request_id']);
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
