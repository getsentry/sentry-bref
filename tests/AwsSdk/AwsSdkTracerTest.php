<?php declare(strict_types=1);

namespace Sentry\Bref\Test\AwsSdk;

use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use PHPUnit\Framework\TestCase;
use Sentry\Bref\AwsSdk\AwsSdkTracer;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

class AwsSdkTracerTest extends TestCase
{
    public function testTracesAwsSdkCallsAsChildSpans(): void
    {
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);

        $client = $this->createSqsClient();
        AwsSdkTracer::trace($client);
        $client->listQueues();

        $spans = array_values(array_filter(
            $transaction->getSpanRecorder()?->getSpans() ?? [],
            fn (Span $span) => $span !== $transaction,
        ));
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

        $client = $this->createSqsClient();
        AwsSdkTracer::trace($client);
        $result = $client->listQueues();

        $this->assertSame(['https://sqs.eu-west-1.amazonaws.com/123456789012/queue'], $result->get('QueueUrls'));
        $this->assertNull($hub->getSpan());
    }

    private function createSqsClient(): SqsClient
    {
        return new SqsClient([
            'region' => 'eu-west-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => new MockHandler([
                new Result(['QueueUrls' => ['https://sqs.eu-west-1.amazonaws.com/123456789012/queue']]),
            ]),
        ]);
    }
}
