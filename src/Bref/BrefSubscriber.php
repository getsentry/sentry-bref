<?php

declare(strict_types=1);

namespace Sentry\Bref\Bref;

use Bref\Context\Context;
use Bref\Event\Handler;
use Bref\Event\Http\HttpRequestEvent;
use Bref\FpmRuntime\FpmHandler;
use Bref\LaravelBridge\Http\OctaneHandler;
use Bref\LaravelBridge\Queue\QueueHandler;
use Bref\Listener\BrefEventSubscriber;
use Bref\Runtime\ColdStartTracker;
use Illuminate\Container\Container;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\Bref\BrefSpans;
use Sentry\Bref\InternalErrors;
use Sentry\Bref\Laravel\LaravelBridge;
use Sentry\Bref\StartupTracking;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

use function Sentry\captureEvent;
use function Sentry\captureException;
use function Sentry\startTransaction;

/**
 * @internal
 */
class BrefSubscriber extends BrefEventSubscriber
{
    private ?float $startUpStart = null;
    private ?float $startUpEnd = null;
    private ?Span $currentInvokeSpan = null;
    private ?HubInterface $invocationHub = null;
    /**
     * The Lambda context of the invocation in progress.
     */
    private static ?Context $invocationContext = null;
    private static bool $eventProcessorRegistered = false;

    public function beforeStartup(): void
    {
        $this->startUpStart = microtime(true);
    }

    public function afterStartup(): void
    {
        try {
            // On a cold start, the startup is reported with the first invocation (see `beforeInvoke()`)
            // On warm invocations the startup only happens when Bref's bootstrap process reboots, i.e.
            // after the response was sent. So that span/time does not count in the transaction because
            // it's not part of the client response time/experience.
            if (ColdStartTracker::currentInvocationIsColdStart()) {
                $this->startUpEnd = microtime(true);
            } else {
                // We're reporting a new transaction as a child of the previous one
                $context = StartupTracking::createChildTransaction();
                if ($context && $context->getParentSampled()) {
                    $functionName = $_SERVER['AWS_LAMBDA_FUNCTION_NAME'] ?? '';

                    $context->setName('bref.startup');
                    $context->setOp('function.aws');
                    $context->setOrigin('auto.function.aws_lambda');
                    $context->setSource(TransactionSource::task());
                    $context->setStartTimestamp($this->startUpStart);
                    $context->setDescription('Bref and the entire PHP process restarted at the end of the previous invocation, this is by design. This happened outside of the previous invocation duration/HTTP response time (if it was an HTTP request), this is why it is tracked as a child Sentry transaction. Check out the Sentry trace to see the previous invocation. You can disable that behavior via BREF_LOOP_MAX: https://bref.sh/docs/environment/performances#bref-for-event-driven-functions');
                    $context->setData([
                        'faas.name' => $functionName,
                        'cloud.provider' => 'aws',
                        'cloud.platform' => 'aws_lambda',
                        'cloud.region' => $_SERVER['AWS_REGION'] ?? '',
                    ]);
                    $context->setTags([
                        'cloud.region' => $_SERVER['AWS_REGION'] ?? '',
                        'faas.name' => $functionName,
                    ]);

                    $hub = SentrySdk::getCurrentHub();
                    $transaction = $hub->startTransaction($context);
                    $hub->setSpan($transaction);
                    $spanContext = new SpanContext();
                    $spanContext->setOp('bref.startup');
                    $spanContext->setStartTimestamp($this->startUpStart);
                    $startupSpan = $transaction->startChild($spanContext);
                    $hub->setSpan($startupSpan);
                    $startupSpan->finish();
                    $hub->setSpan($transaction);
                    $transaction->setStatus(SpanStatus::ok());
                    $transaction->finish();

                    StartupTracking::clear();
                }
            }
        } catch (\Throwable $e) {
            InternalErrors::log($e);
        }
    }

    public function beforeInvoke(
        callable|Handler|RequestHandlerInterface $handler,
        mixed $event,
        Context $context,
    ): void {
        try {
            // When Lambda initializes the instance proactively, i.e. before it is used, the cold start
            // is not part of the invocation: reporting it would include the time the instance waited
            if (ColdStartTracker::currentInvocationIsUserFacingColdStart()) {
                BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', ColdStartTracker::getColdStartBeginningTime(), microtime(true));
                if ($this->startUpStart !== null && $this->startUpEnd !== null) {
                    BrefSpans::storeSpan('bref.startup', 'Bref startup', $this->startUpStart, $this->startUpEnd);
                }
            }

            // Ignore PHP-FPM handlers because the FPM worker will be responsible for reporting the transaction
            if ($handler instanceof FpmHandler) {
                return;
            }

            $this->startInvocationContext();
            $this->addLambdaContextsToEvents($context);

            // The framework's Sentry SDK creates a better transaction: named after the job or the
            // route, and continuing the trace of the code that dispatched the job or sent the request
            if ($this->frameworkCreatesTheTransaction($handler)) {
                return;
            }

            $this->currentInvokeSpan = $this->startTransaction($handler, $context);
        } catch (\Throwable $e) {
            InternalErrors::log($e);
        }
    }

    public function afterInvoke(
        callable|Handler|RequestHandlerInterface $handler,
        mixed $event,
        Context $context,
        mixed $result,
        ?\Throwable $error = null,
    ): void {
        try {
            if ($handler instanceof FpmHandler) {
                // If there was a Bref error (timeout, response too big, etc.), we need to report it.
                // In case of timeouts FPM was hard stopped before the transaction was finished and
                // anything could be reported to Sentry.
                // In case of other errors (like response too big), the error happened outside the
                // FPM worker so it is unknown to the framework (where Sentry is configured).
                if ($error !== null) {
                    $this->reportError($error, $event);
                }

                // Ignore PHP-FPM handlers because the FPM worker will be responsible for reporting the transaction
                return;
            }

            if ($error !== null) {
                captureException($error);
            }

            // No transaction to finish: the framework created it, or `beforeInvoke()` failed
            if ($this->currentInvokeSpan === null) {
                return;
            }

            $status = $error !== null ? SpanStatus::internalError() : SpanStatus::ok();
            $this->currentInvokeSpan->setStatus($status);
            // The transaction too: that is where Sentry looks for failed invocations
            SentrySdk::getCurrentHub()->getTransaction()?->setStatus($status);

            $this->stopTransaction($this->currentInvokeSpan);
            $this->currentInvokeSpan = null;
        } catch (\Throwable $e) {
            InternalErrors::log($e);
        } finally {
            $this->endInvocationContext();
            self::$invocationContext = null;
        }
    }

    /**
     * The process is reused for the next invocations (`BREF_LOOP_MAX`, Octane): what an invocation
     * adds to the scope (breadcrumbs, tags, user…) must not end up in the events of the next ones.
     */
    private function startInvocationContext(): void
    {
        // The same hub, not a new one: the hub is often kept since the application booted
        // (Laravel's container, Monolog handlers…), the events it sends must be part of the invocation
        $hub = SentrySdk::getCurrentHub();
        SentrySdk::startContext($hub);
        $hub->pushScope();
        $this->invocationHub = $hub;
    }

    private function endInvocationContext(): void
    {
        $hub = $this->invocationHub;
        if ($hub === null) {
            return;
        }
        $this->invocationHub = null;

        try {
            $hub->popScope();
            // Sends the logs and metrics buffered during the invocation:
            // Lambda can freeze or stop the environment once the invocation ends
            SentrySdk::endContext();
        } catch (\Throwable $e) {
            InternalErrors::log($e);
        }
    }

    protected function startTransaction(
        callable|Handler|RequestHandlerInterface $handler,
        Context $context,
    ): Span {
        $functionArn = $context->getInvokedFunctionArn();
        $functionName = $_SERVER['AWS_LAMBDA_FUNCTION_NAME'] ?? '';
        $functionVersion = $_SERVER['AWS_LAMBDA_FUNCTION_VERSION'] ?? '';

        // Like the Sentry SDK for JavaScript (`@sentry/aws-serverless`)
        $transactionContext = new TransactionContext();
        $transactionContext->setName($functionName);
        $transactionContext->setOp('function.aws');
        $transactionContext->setOrigin('auto.function.aws_lambda');
        $transactionContext->setSource(TransactionSource::component());
        // See https://getsentry.github.io/sentry-conventions/attributes/
        $transactionContext->setData(array_filter([
            'faas.invocation_id' => $context->getAwsRequestId(),
            'faas.name' => $functionName,
            'faas.version' => $functionVersion,
            'cloud.provider' => 'aws',
            'cloud.platform' => 'aws_lambda',
            'cloud.region' => $_SERVER['AWS_REGION'] ?? '',
            'cloud.resource_id' => $functionArn,
            // arn:aws:lambda:<region>:<account-id>:function:<name>
            'cloud.account.id' => explode(':', $functionArn)[4] ?? null,
        ]) + [
            // Not when Lambda initialized the instance before the invocation (proactive initialization)
            'faas.coldstart' => ColdStartTracker::currentInvocationIsUserFacingColdStart(),
        ]);
        $transactionContext->setTags([
            'cloud.region' => $_SERVER['AWS_REGION'] ?? '',
            // 'faas.trigger' => 'pubsub',
            'faas.name' => $functionName,
            'faas.version' => $functionVersion,
        ]);
        $transaction = startTransaction($transactionContext);
        BrefSpans::loadBrefSpans($transaction);

        $hub = SentrySdk::getCurrentHub();
        $hub->setSpan($transaction);

        $spanContext = new SpanContext();
        $spanContext->setOp('function');
        $spanContext->setOrigin('auto.function.aws_lambda');
        $spanContext->setDescription(\is_object($handler) ? $handler::class : 'handler');
        $handleSpan = $transaction->startChild($spanContext);
        $hub->setSpan($handleSpan);

        // TODO look at distributed traces

        return $handleSpan;
    }

    /**
     * Laravel queues and Octane, when the Sentry SDK for Laravel is installed.
     */
    private function frameworkCreatesTheTransaction(callable|Handler|RequestHandlerInterface $handler): bool
    {
        if (!class_exists(\Sentry\Laravel\Integration::class)) {
            return false;
        }

        if ($handler instanceof QueueHandler) {
            // Jobs can be configured to not be traced as transactions
            $container = Container::getInstance();

            return !$container->bound('config') || (bool) $container->make('config')->get('sentry.tracing.queue_job_transactions', true);
        }

        return $handler instanceof OctaneHandler;
    }

    /**
     * Added to the events rather than set on the scope: frameworks juggle with scopes (sentry-laravel
     * keeps the scope of a failed job until the next job), a scope can hold a previous invocation.
     */
    private function addLambdaContextsToEvents(Context $context): void
    {
        self::$invocationContext = $context;
        if (self::$eventProcessorRegistered) {
            return;
        }

        // Global: applies to the events of every hub and scope
        Scope::addGlobalEventProcessor(static function (Event $event): Event {
            $context = self::$invocationContext;
            if ($context === null) {
                return $event;
            }

            // An exception thrown here would drop the event
            try {
                self::setLambdaContexts($event, $context);
            } catch (\Throwable $e) {
                InternalErrors::log($e);
            }

            return $event;
        });
        self::$eventProcessorRegistered = true;
    }

    private static function setLambdaContexts(Event $event, Context $context): void
    {
        $functionName = $_SERVER['AWS_LAMBDA_FUNCTION_NAME'] ?? '';
        $functionVersion = $_SERVER['AWS_LAMBDA_FUNCTION_VERSION'] ?? '';
        $logStreamName = $_SERVER['AWS_LAMBDA_LOG_STREAM_NAME'] ?? '';

        // Inspired by the Node implementation for Lambda
        // See https://github.com/getsentry/sentry-javascript/blob/da7c95939f9dc36abcf6c4cabd08ad4fdbb7d920/packages/serverless/src/awslambda.ts#L225
        $event->setContext('aws.lambda', [
            'aws_request_id' => $context->getAwsRequestId(),
            'function_name' => $functionName,
            'function_version' => $functionVersion,
            'invoked_function_arn' => $context->getInvokedFunctionArn(),
            'initialization_type' => $_SERVER['AWS_LAMBDA_INITIALIZATION_TYPE'] ?? '',
            // When the event happens
            'remaining_time_in_millis' => $context->getRemainingTimeInMillis(),
        ]);
        $logGroupName = $_SERVER['AWS_LAMBDA_LOG_GROUP_NAME'] ?? '';
        $event->setContext('aws.cloudwatch.logs', [
            'log_group' => $logGroupName,
            'log_stream' => $logStreamName,
            'url' => 'https://console.aws.amazon.com/cloudwatch/home?region=' . ($_SERVER['AWS_REGION'] ?? '') . '#logsV2:log-groups/log-group/' . urlencode($logGroupName) . '/log-events/' . urlencode($logStreamName) . '?filterPattern="' . $context->getAwsRequestId() . '"',
        ]);
    }

    protected function stopTransaction(Span $handleSpan): void
    {
        $hub = SentrySdk::getCurrentHub();
        $transaction = $hub->getTransaction();

        StartupTracking::storeReferenceToCurrentTransaction();

        $handleSpan->finish();

        $hub->setSpan($transaction);
        $hub->getTransaction()?->finish();
    }

    private function reportError(\Throwable $error, mixed $event): void
    {
        $httpEvent = new HttpRequestEvent($event);

        $laravel = null;
        if (LaravelBridge::isInstalled()) {
            $laravel = LaravelBridge::bootLaravel();
        }

        $hint = new EventHint();
        if ($hint->exception === null) {
            $hint->exception = $error;
        }

        $sentryEvent = Event::createEvent();

        // Rebuild the full URL
        // According to these docs this is what Sentry expects: https://develop.sentry.dev/sdk/data-model/event-payloads/request/
        $uri = $httpEvent->getUri();
        $headers = $httpEvent->getHeaders();
        $host = $headers['x-forwarded-host'][0] ?? $headers['host'][0] ?? 'unknown-host';
        $fullUri = 'https://' . $host . $uri;

        $sentryEvent->setRequest([
            'url' => $fullUri,
            'method' => $httpEvent->getMethod(),
            'query_string' => $httpEvent->getQueryString(),
        ]);
        captureEvent($sentryEvent, $hint);

        $laravel?->terminate();
    }
}
