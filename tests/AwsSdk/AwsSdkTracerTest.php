<?php declare(strict_types=1);

namespace Sentry\Bref\Test\AwsSdk;

use Aws\AwsClient;
use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Sns\SnsClient;
use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\Bref\AwsSdk\AwsSdkTracer;
use Sentry\Bref\InternalErrors;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
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

    /**
     * @param class-string<AwsClient> $clientClass
     * @param array<string, mixed> $params
     * @param array<string, mixed> $result
     * @param array<string, mixed> $expectedData
     * @dataProvider provideAwsResources
     */
    public function testRecordsTheAwsResource(string $clientClass, string $operation, array $params, array $result, array $expectedData): void
    {
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);

        $client = new $clientClass([
            'region' => 'eu-west-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => new MockHandler([new Result($result)]),
        ]);
        AwsSdkTracer::trace($client);
        $client->execute($client->getCommand($operation, $params));

        $spans = $this->childSpans($transaction);
        $this->assertCount(1, $spans);
        foreach ($expectedData as $key => $value) {
            $this->assertSame($value, $spans[0]->getData()[$key] ?? null, $key);
        }
    }

    /**
     * @return iterable<string, array{class-string<AwsClient>, string, array<string, mixed>, array<string, mixed>, array<string, mixed>}>
     */
    public static function provideAwsResources(): iterable
    {
        yield 'SQS queue' => [SqsClient::class, 'SendMessage', ['QueueUrl' => self::QUEUE_URL, 'MessageBody' => 'hello'], [], [
            'aws.sqs.queue.url' => self::QUEUE_URL,
        ]];
        yield 'SNS topic' => [SnsClient::class, 'Publish', ['TopicArn' => 'arn:aws:sns:eu-west-1:123456789012:topic', 'Message' => 'hello'], [], [
            'aws.sns.topic.arn' => 'arn:aws:sns:eu-west-1:123456789012:topic',
        ]];
        yield 'DynamoDB table' => [DynamoDbClient::class, 'GetItem', ['TableName' => 'users', 'Key' => ['id' => ['S' => '1']]], [], [
            'aws.dynamodb.table_names' => ['users'],
        ]];
        yield 'DynamoDB batch' => [DynamoDbClient::class, 'BatchGetItem', ['RequestItems' => [
            'users' => ['Keys' => [['id' => ['S' => '1']]]],
            'posts' => ['Keys' => [['id' => ['S' => '1']]]],
        ]], [], [
            'aws.dynamodb.table_names' => ['users', 'posts'],
        ]];
        yield 'S3 bucket' => [S3Client::class, 'GetObject', ['Bucket' => 'my-bucket', 'Key' => 'file.txt'], [], [
            'aws.s3.bucket' => 'my-bucket',
        ]];
        yield 'Secrets Manager secret' => [SecretsManagerClient::class, 'GetSecretValue', ['SecretId' => 'my-secret'], [
            'ARN' => 'arn:aws:secretsmanager:eu-west-1:123456789012:secret:my-secret-AbCdEf',
        ], [
            'aws.secretsmanager.secret.arn' => 'arn:aws:secretsmanager:eu-west-1:123456789012:secret:my-secret-AbCdEf',
        ]];
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

    public function testNeverBreaksTheAwsCall(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('getSpan')->willThrowException(new \RuntimeException('Sentry is broken'));
        SentrySdk::setCurrentHub($hub);
        InternalErrors::setLogger(new NullLogger);

        try {
            $client = $this->createSqsClient(fn () => new Result(['QueueUrls' => [self::QUEUE_URL]]));
            AwsSdkTracer::trace($client);
            $result = $client->listQueues();
        } finally {
            InternalErrors::setLogger(null);
            SentrySdk::init();
        }

        $this->assertSame([self::QUEUE_URL], $result->get('QueueUrls'));
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
