<?php declare(strict_types=1);

namespace Sentry\Bref\Test\Bref;

use Bref\Context\Context;
use Bref\Event\Handler;
use Bref\FpmRuntime\FpmHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Bref\Bref\BrefSubscriber;
use Sentry\Bref\Test\Support\CapturingTransport;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

class BrefSubscriberTest extends TestCase
{
    private const FILES = [
        '/tmp/bref-sentry-cold-start',
        '/tmp/bref-sentry-spans.json',
        '/tmp/bref-sentry-startup.txt',
    ];

    private CapturingTransport $transport;
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->deleteFiles();
        $this->server = $_SERVER;
        $_SERVER['AWS_LAMBDA_FUNCTION_NAME'] = 'my-function';
        $_SERVER['AWS_LAMBDA_FUNCTION_VERSION'] = '$LATEST';
        $_SERVER['AWS_REGION'] = 'eu-west-3';
        $_SERVER['AWS_LAMBDA_LOG_GROUP_NAME'] = '/aws/lambda/my-function';
        $_SERVER['AWS_LAMBDA_LOG_STREAM_NAME'] = '2026/10/09/[$LATEST]abc';
        $_SERVER['AWS_LAMBDA_INITIALIZATION_TYPE'] = 'on-demand';

        $this->transport = new CapturingTransport;
        SentrySdk::init()->bindClient(ClientBuilder::create([
            'dsn' => 'https://public@example.com/1',
            'traces_sample_rate' => 1.0,
            'default_integrations' => false,
        ])->setTransport($this->transport)->getClient());
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $this->deleteFiles();
        SentrySdk::init();
    }

    public function testCreatesATransactionForEachInvocation(): void
    {
        $subscriber = $this->createWarmSubscriber();
        $handler = $this->createHandler();

        $subscriber->beforeInvoke($handler, [], Context::fake());
        $subscriber->afterInvoke($handler, [], Context::fake(), 'result');

        $this->assertCount(0, $this->transport->errors());
        $transaction = $this->singleTransaction();
        $this->assertSame('my-function', $transaction->getTransaction());
        $traceContext = $transaction->getContexts()['trace'];
        $this->assertSame('function.aws.lambda', $traceContext['op']);
        $this->assertSame([
            'cloud.region' => 'eu-west-3',
            'faas.name' => 'my-function',
            'faas.version' => '$LATEST',
        ], $transaction->getTags());
        $this->assertSame('fake-aws-request-id', $traceContext['data']['faas.execution']);
        $this->assertSame('fake-invoked-function-arn', $traceContext['data']['faas.id']);
        // The handler is a child span
        $this->assertCount(1, $transaction->getSpans());
        $this->assertEquals(SpanStatus::ok(), $transaction->getSpans()[0]->getStatus());
        $this->assertNotNull($transaction->getSpans()[0]->getEndTimestamp());
    }

    public function testAddsLambdaContextsToEvents(): void
    {
        $subscriber = $this->createWarmSubscriber();
        $handler = $this->createHandler();

        $subscriber->beforeInvoke($handler, [], Context::fake());
        $subscriber->afterInvoke($handler, [], Context::fake(), 'result');

        $contexts = $this->singleTransaction()->getContexts();
        $this->assertSame('fake-aws-request-id', $contexts['aws.lambda']['aws_request_id']);
        $this->assertSame('my-function', $contexts['aws.lambda']['function_name']);
        $this->assertSame('$LATEST', $contexts['aws.lambda']['function_version']);
        $this->assertSame('fake-invoked-function-arn', $contexts['aws.lambda']['invoked_function_arn']);
        $this->assertSame('on-demand', $contexts['aws.lambda']['initialization_type']);
        $this->assertSame('/aws/lambda/my-function', $contexts['aws.cloudwatch.logs']['log_group']);
        $this->assertSame('2026/10/09/[$LATEST]abc', $contexts['aws.cloudwatch.logs']['log_stream']);
        $this->assertSame(
            'https://console.aws.amazon.com/cloudwatch/home?region=eu-west-3#logsV2:log-groups/log-group/%2Faws%2Flambda%2Fmy-function/log-events/2026%2F10%2F09%2F%5B%24LATEST%5Dabc?filterPattern="fake-aws-request-id"',
            $contexts['aws.cloudwatch.logs']['url'],
        );
    }

    public function testCapturesTheInvocationError(): void
    {
        $subscriber = $this->createWarmSubscriber();
        $handler = $this->createHandler();
        $error = new RuntimeException('Invocation failed');

        $subscriber->beforeInvoke($handler, [], Context::fake());
        $subscriber->afterInvoke($handler, [], Context::fake(), null, $error);

        $errors = $this->transport->errors();
        $this->assertCount(1, $errors);
        $this->assertSame('Invocation failed', $errors[0]->getExceptions()[0]->getValue());
        $this->assertSame('fake-aws-request-id', $errors[0]->getContexts()['aws.lambda']['aws_request_id']);
        // The error is linked to the invocation's trace
        $transaction = $this->singleTransaction();
        $this->assertSame($transaction->getContexts()['trace']['trace_id'], $errors[0]->getContexts()['trace']['trace_id']);
        $this->assertEquals(SpanStatus::internalError(), $transaction->getSpans()[0]->getStatus());
    }

    public function testAddsTheColdStartToTheFirstInvocation(): void
    {
        // No marker file: this is a cold start
        $subscriber = new BrefSubscriber;
        $handler = $this->createHandler();

        $subscriber->beforeStartup();
        $subscriber->afterStartup();
        $subscriber->beforeInvoke($handler, [], Context::fake());
        $subscriber->afterInvoke($handler, [], Context::fake(), 'result');

        $transaction = $this->singleTransaction();
        $ops = array_map(fn (Span $span) => $span->getOp(), $transaction->getSpans());
        $this->assertContains('lambda.cold-start', $ops);
        $this->assertContains('bref.startup', $ops);
    }

    public function testReportsWarmRestartsAsAChildTransaction(): void
    {
        $handler = $this->createHandler();
        $first = $this->createWarmSubscriber();
        $first->beforeInvoke($handler, [], Context::fake());
        $first->afterInvoke($handler, [], Context::fake(), 'result');

        // The PHP process restarts (BREF_LOOP_MAX=1)
        $restarted = $this->createWarmSubscriber();
        $restarted->beforeStartup();
        $restarted->afterStartup();

        $transactions = $this->transport->transactions();
        $this->assertCount(2, $transactions);
        $this->assertSame('bref.startup', $transactions[1]->getTransaction());
        $this->assertSame(
            $transactions[0]->getContexts()['trace']['trace_id'],
            $transactions[1]->getContexts()['trace']['trace_id'],
        );
    }

    public function testLeavesFpmInvocationsToTheFramework(): void
    {
        $subscriber = $this->createWarmSubscriber();
        $handler = new FpmHandler('index.php');

        $subscriber->beforeInvoke($handler, $this->createHttpEvent(), Context::fake());
        $subscriber->afterInvoke($handler, $this->createHttpEvent(), Context::fake(), 'result');

        $this->assertSame([], $this->transport->events);
    }

    public function testReportsBrefErrorsOfFpmInvocations(): void
    {
        $_SERVER['LAMBDA_TASK_ROOT'] = __DIR__ . '/../Fixtures/laravel';
        $subscriber = $this->createWarmSubscriber();
        $handler = new FpmHandler('index.php');
        // e.g. a PHP-FPM timeout
        $error = new RuntimeException('The PHP script timed out');

        $subscriber->beforeInvoke($handler, $this->createHttpEvent(), Context::fake());
        $subscriber->afterInvoke($handler, $this->createHttpEvent(), Context::fake(), null, $error);

        $this->assertSame([], $this->transport->transactions());
        $errors = $this->transport->errors();
        $this->assertCount(1, $errors);
        $this->assertSame('The PHP script timed out', $errors[0]->getExceptions()[0]->getValue());
        $this->assertSame([
            'url' => 'https://example.com/hello?foo=bar',
            'method' => 'GET',
            'query_string' => 'foo=bar',
        ], $errors[0]->getRequest());
    }

    /**
     * A subscriber for a PHP process that is not the first one of the Lambda instance.
     */
    private function createWarmSubscriber(): BrefSubscriber
    {
        touch('/tmp/bref-sentry-cold-start');

        return new BrefSubscriber;
    }

    private function createHandler(): Handler
    {
        return new class implements Handler {
            public function handle(mixed $event, Context $context): mixed
            {
                return 'result';
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function createHttpEvent(): array
    {
        return [
            'version' => '2.0',
            'rawPath' => '/hello',
            'rawQueryString' => 'foo=bar',
            'headers' => ['host' => 'example.com'],
            'requestContext' => ['http' => ['method' => 'GET']],
        ];
    }

    private function singleTransaction(): Event
    {
        $transactions = $this->transport->transactions();
        $this->assertCount(1, $transactions);

        return $transactions[0];
    }

    private function deleteFiles(): void
    {
        foreach (self::FILES as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
