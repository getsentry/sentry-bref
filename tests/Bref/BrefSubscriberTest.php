<?php declare(strict_types=1);

namespace Sentry\Bref\Test\Bref;

use Bref\Context\Context;
use Bref\Event\Handler;
use Bref\FpmRuntime\FpmHandler;
use Bref\LaravelBridge\Http\OctaneHandler;
use Bref\LaravelBridge\Queue\QueueHandler;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Bref\Logger\StderrLogger;
use Bref\Runtime\ColdStartTracker;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Psr\Log\LogLevel;
use RuntimeException;
use Sentry\Bref\Bref\BrefSubscriber;
use Sentry\Bref\InternalErrors;
use Sentry\Bref\Test\Support\CapturingTransport;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionSource;

class BrefSubscriberTest extends TestCase
{
    private const FILES = [
        // Bref's cold start tracker
        '/tmp/.bref-cold-start',
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
        Container::setInstance(null);
        $_SERVER = $this->server;
        $this->deleteFiles();
        SentrySdk::init();
    }

    public function testCreatesATransactionForEachInvocation(): void
    {
        $subscriber = $this->createWarmSubscriber();
        $handler = $this->createHandler();

        $context = new Context('request-id', (time() + 60) * 1000, 'arn:aws:lambda:eu-west-3:123456789012:function:my-function', 'trace-id');

        $subscriber->beforeInvoke($handler, [], $context);
        $subscriber->afterInvoke($handler, [], $context, 'result');

        $this->assertCount(0, $this->transport->errors());
        $transaction = $this->singleTransaction();
        $this->assertSame('my-function', $transaction->getTransaction());
        $this->assertEquals(TransactionSource::component(), $transaction->getSdkMetadata('transaction_metadata')?->getSource());
        $traceContext = $transaction->getContexts()['trace'];
        $this->assertSame('function.aws', $traceContext['op']);
        $this->assertSame('auto.function.aws_lambda', $traceContext['origin']);
        $this->assertSame([
            'faas.invocation_id' => 'request-id',
            'faas.name' => 'my-function',
            'faas.version' => '$LATEST',
            'cloud.provider' => 'aws',
            'cloud.platform' => 'aws_lambda',
            'cloud.region' => 'eu-west-3',
            'cloud.resource_id' => 'arn:aws:lambda:eu-west-3:123456789012:function:my-function',
            'cloud.account.id' => '123456789012',
        ], $traceContext['data']);
        $this->assertSame([
            'cloud.region' => 'eu-west-3',
            'faas.name' => 'my-function',
            'faas.version' => '$LATEST',
        ], $transaction->getTags());
        // The handler is a child span
        $this->assertCount(1, $transaction->getSpans());
        $handlerSpan = $transaction->getSpans()[0];
        $this->assertSame('function', $handlerSpan->getOp());
        $this->assertSame($handler::class, $handlerSpan->getDescription());
        $this->assertEquals(SpanStatus::ok(), $handlerSpan->getStatus());
        $this->assertNotNull($handlerSpan->getEndTimestamp());
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
        $this->resetColdStartTracker();
        // Like the Bref runtime does for the first process of a Lambda instance
        ColdStartTracker::init();
        $subscriber = new BrefSubscriber;
        $subscriber->beforeStartup();
        $subscriber->afterStartup();
        ColdStartTracker::coldStartFinished();
        ColdStartTracker::invocationStarted();
        $handler = $this->createHandler();

        $subscriber->beforeInvoke($handler, [], Context::fake());
        $subscriber->afterInvoke($handler, [], Context::fake(), 'result');

        $transaction = $this->singleTransaction();
        $spans = [];
        foreach ($transaction->getSpans() as $span) {
            $spans[$span->getOp()] = $span;
        }
        $this->assertArrayHasKey('lambda.cold-start', $spans);
        $this->assertArrayHasKey('bref.startup', $spans);
        $this->assertSame(ColdStartTracker::getColdStartBeginningTime(), $spans['lambda.cold-start']->getStartTimestamp());
        // The transaction starts with the cold start
        $this->assertSame(ColdStartTracker::getColdStartBeginningTime(), $transaction->getStartTimestamp());
    }

    public function testLeavesProactiveInitializationsOutOfTheInvocation(): void
    {
        $this->resetColdStartTracker();
        ColdStartTracker::init();
        $subscriber = new BrefSubscriber;
        $subscriber->beforeStartup();
        $subscriber->afterStartup();
        ColdStartTracker::coldStartFinished();
        // Lambda initialized the instance before using it: it waited before the first invocation
        usleep(100_000);
        ColdStartTracker::invocationStarted();
        $handler = $this->createHandler();
        $invocationStart = microtime(true);

        $subscriber->beforeInvoke($handler, [], Context::fake());
        $subscriber->afterInvoke($handler, [], Context::fake(), 'result');

        $transaction = $this->singleTransaction();
        $ops = array_map(fn (Span $span) => $span->getOp(), $transaction->getSpans());
        $this->assertNotContains('lambda.cold-start', $ops);
        $this->assertNotContains('bref.startup', $ops);
        $this->assertGreaterThanOrEqual($invocationStart, $transaction->getStartTimestamp());
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

    public function testLetsSentryLaravelTraceQueueJobs(): void
    {
        $subscriber = $this->createWarmSubscriber();
        $handler = $this->createMock(QueueHandler::class);

        $subscriber->beforeInvoke($handler, [], Context::fake());
        \Sentry\captureMessage('Logged by a job');
        $subscriber->afterInvoke($handler, [], Context::fake(), null);

        // sentry-laravel creates one transaction per job, named after the job class
        $this->assertSame([], $this->transport->transactions());
        // Events still get the Lambda contexts
        $this->assertSame('fake-aws-request-id', $this->transport->errors()[0]->getContexts()['aws.lambda']['aws_request_id']);
    }

    public function testTracesQueueJobsWhenSentryLaravelDoesNot(): void
    {
        Container::setInstance($container = new Container);
        $container->instance('config', new Repository(['sentry' => ['tracing' => ['queue_job_transactions' => false]]]));
        $subscriber = $this->createWarmSubscriber();
        $handler = $this->createMock(QueueHandler::class);

        $subscriber->beforeInvoke($handler, [], Context::fake());
        $subscriber->afterInvoke($handler, [], Context::fake(), null);

        $this->assertSame('my-function', $this->singleTransaction()->getTransaction());
    }

    public function testLetsSentryLaravelTraceOctaneRequests(): void
    {
        $subscriber = $this->createWarmSubscriber();
        $handler = $this->createMock(OctaneHandler::class);

        $subscriber->beforeInvoke($handler, $this->createHttpEvent(), Context::fake());
        $subscriber->afterInvoke($handler, $this->createHttpEvent(), Context::fake(), null, new RuntimeException('Bref error'));

        // sentry-laravel creates the transaction, named after the route
        $this->assertSame([], $this->transport->transactions());
        // Errors outside of Laravel are still captured
        $errors = $this->transport->errors();
        $this->assertCount(1, $errors);
        $this->assertSame('Bref error', $errors[0]->getExceptions()[0]->getValue());
        $this->assertSame('fake-aws-request-id', $errors[0]->getContexts()['aws.lambda']['aws_request_id']);
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

    public function testAFailureDuringStartupDoesNotBreakTheRuntime(): void
    {
        // e.g. a file left corrupted by a previous process
        file_put_contents('/tmp/bref-sentry-startup.txt', 'not JSON');
        $subscriber = $this->createWarmSubscriber();

        $logs = $this->captureLogs(function () use ($subscriber) {
            $subscriber->beforeStartup();
            $subscriber->afterStartup();
        });

        $this->assertStringContainsString('Sentry Bref integration error: Syntax error', $logs);
    }

    public function testAFailureBeforeTheInvocationDoesNotFailTheInvocation(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('startTransaction')->willThrowException(new RuntimeException('Sentry is broken'));
        SentrySdk::setCurrentHub($hub);
        $subscriber = $this->createWarmSubscriber();
        $handler = $this->createHandler();

        $logs = $this->captureLogs(function () use ($subscriber, $handler) {
            $subscriber->beforeInvoke($handler, [], Context::fake());
            $subscriber->afterInvoke($handler, [], Context::fake(), 'result');
        });

        $this->assertStringContainsString('Sentry Bref integration error: Sentry is broken', $logs);
    }

    /**
     * Runs the callback and returns what this package logged.
     */
    private function captureLogs(callable $callback): string
    {
        $stream = fopen('php://memory', 'r+');
        InternalErrors::setLogger(new StderrLogger(LogLevel::DEBUG, $stream));
        try {
            $callback();
        } finally {
            InternalErrors::setLogger(null);
        }
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * A subscriber for a PHP process that is not the first one of the Lambda instance.
     */
    private function createWarmSubscriber(): BrefSubscriber
    {
        $this->resetColdStartTracker();
        touch('/tmp/.bref-cold-start');
        ColdStartTracker::init();
        ColdStartTracker::coldStartFinished();

        return new BrefSubscriber;
    }

    /**
     * The tracker's state is static: reset it like a new PHP process would.
     */
    private function resetColdStartTracker(): void
    {
        $tracker = new ReflectionClass(ColdStartTracker::class);
        $tracker->setStaticPropertyValue('currentInvocationIsColdStart', false);
        $tracker->setStaticPropertyValue('coldStartBeginningTime', null);
        $tracker->setStaticPropertyValue('coldStartEndedTime', null);
        $tracker->setStaticPropertyValue('hasFirstInvocationStarted', false);
        $tracker->setStaticPropertyValue('wasProactiveInitialization', false);
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
