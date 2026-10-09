<?php declare(strict_types=1);

namespace Sentry\Bref\Bref;

use Bref\Context\Context;
use Bref\Event\Handler;
use Bref\Event\Http\HttpRequestEvent;
use Bref\FpmRuntime\FpmHandler;
use Bref\Listener\BrefEventSubscriber;
use Bref\Runtime\ColdStartTracker;
use Sentry\Bref\BrefSpans;
use Sentry\Bref\InternalErrors;
use Sentry\Bref\Laravel\LaravelBridge;
use Sentry\Bref\StartupTracking;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Throwable;

use function Sentry\captureEvent;
use function Sentry\captureException;
use function Sentry\startTransaction;

/**
 * @internal
 */
class BrefSubscriber extends BrefEventSubscriber
{
    private float|null $startUpStart = null;
    private float|null $startUpEnd = null;
    private Span|null $currentInvokeSpan = null;

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
                    $context->setOp('function.aws.lambda');
                    $context->setSource(TransactionSource::task());
                    $context->setStartTimestamp($this->startUpStart);
                    $context->setDescription('Bref and the entire PHP process restarted at the end of the previous invocation, this is by design. This happened outside of the previous invocation duration/HTTP response time (if it was an HTTP request), this is why it is tracked as a child Sentry transaction. Check out the Sentry trace to see the previous invocation. You can disable that behavior via BREF_LOOP_MAX: https://bref.sh/docs/environment/performances#bref-for-event-driven-functions');
                    $context->setData([
                        'aws.lambda-name' => $functionName,
                        'cloud.provider' => 'aws',
                        'cloud.region' => $_SERVER['AWS_REGION'] ?? '',
                    ]);
                    $context->setTags([
                        'cloud.region' => $_SERVER['AWS_REGION'] ?? '',
                        'faas.name' => $functionName,
                    ]);

                    $hub = SentrySdk::getCurrentHub();
                    $transaction = $hub->startTransaction($context);
                    $hub->setSpan($transaction);
                    $spanContext = new SpanContext;
                    $spanContext->setOp('bref.startup');
                    $spanContext->setStartTimestamp($this->startUpStart);
                    $startupSpan = $transaction->startChild($spanContext);
                    $hub->setSpan($startupSpan);
                    $startupSpan->finish();
                    $hub->setSpan($transaction);
                    $transaction->finish();

                    StartupTracking::clear();
                }
            }
        } catch (Throwable $e) {
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

            $this->currentInvokeSpan = $this->startTransaction($handler, $context);
        } catch (Throwable $e) {
            InternalErrors::log($e);
        }
    }

    public function afterInvoke(
        callable|Handler|RequestHandlerInterface $handler,
        mixed $event,
        Context $context,
        mixed $result,
        Throwable |null $error = null,
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

            // `beforeInvoke()` failed: there is no transaction to finish
            if ($this->currentInvokeSpan === null) {
                return;
            }

            if ($error !== null) {
                captureException($error);
                $this->currentInvokeSpan->setStatus(SpanStatus::internalError());
            } else {
                $this->currentInvokeSpan->setStatus(SpanStatus::ok());
            }

            $this->stopTransaction($this->currentInvokeSpan);
            $this->currentInvokeSpan = null;
        } catch (Throwable $e) {
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
        $logStreamName = $_SERVER['AWS_LAMBDA_LOG_STREAM_NAME'] ?? '';

        $transactionContext = new TransactionContext;
        $transactionContext->setName($functionName);
        $transactionContext->setOp('function.aws.lambda');
        $transactionContext->setData([
            'aws.request-id' => $context->getAwsRequestId(),
            'aws.lambda-name' => $functionName,
            'aws.lambda-arn' => $functionArn,
            //'aws.trigger' => $trigger,
            // OpenTelemetry standards
            // https://github.com/open-telemetry/opentelemetry-specification/blob/24de67b3827a4e3ab2515cd8ab62d5bcf837c586/specification/trace/semantic_conventions/faas.md
            'cloud.provider' => 'aws',
            'cloud.region' => $_SERVER['AWS_REGION'] ?? '',
            'faas.invoked_provider' => 'aws',
            'faas.invoked_region' => $_SERVER['AWS_REGION'] ?? '',
            //'faas.trigger' => 'pubsub',
            'faas.execution' => $context->getAwsRequestId(),
            'faas.name' => $functionName,
            'faas.id' => $functionArn,
            // Get log stream ID from `AWS_LAMBDA_LOG_STREAM_NAME` env var
            'faas.instance' => $logStreamName,
        ]);
        $transactionContext->setTags([
            'cloud.region' => $_SERVER['AWS_REGION'] ?? '',
            //'faas.trigger' => 'pubsub',
            'faas.name' => $functionName,
            'faas.version' => $functionVersion,
        ]);
        $transaction = startTransaction($transactionContext);
        BrefSpans::loadBrefSpans($transaction);

        $hub = SentrySdk::getCurrentHub();
        $hub->setSpan($transaction);

        // Inspired by the Node implementation for Lambda
        // See https://github.com/getsentry/sentry-javascript/blob/da7c95939f9dc36abcf6c4cabd08ad4fdbb7d920/packages/serverless/src/awslambda.ts#L225
        $hub->configureScope(static function (Scope $scope) use ($functionVersion, $logStreamName, $functionName, $context): void {
            $scope->setContext('aws.lambda', [
                'aws_request_id' => $context->getAwsRequestId(),
                'function_name' => $functionName,
                'function_version' => $functionVersion,
                'invoked_function_arn' => $context->getInvokedFunctionArn(),
                'initialization_type' => $_SERVER['AWS_LAMBDA_INITIALIZATION_TYPE'] ?? '',
                'remaining_time_in_millis' => $context->getRemainingTimeInMillis(),
            ]);
            $logGroupName = $_SERVER['AWS_LAMBDA_LOG_GROUP_NAME'] ?? '';
            $scope->setContext('aws.cloudwatch.logs', [
                'log_group' => $logGroupName,
                'log_stream' => $logStreamName,
                'url' => 'https://console.aws.amazon.com/cloudwatch/home?region=' . ($_SERVER['AWS_REGION'] ?? '') . '#logsV2:log-groups/log-group/' . urlencode($logGroupName) . '/log-events/' . urlencode($logStreamName) . '?filterPattern="' . $context->getAwsRequestId() . '"',
            ]);
        });

        $spanContext = new SpanContext;
        $spanContext->setOp(is_object($handler) ? $handler::class : 'handler');
        $handleSpan = $transaction->startChild($spanContext);
        $hub->setSpan($handleSpan);

        // TODO look at distributed traces

        return $handleSpan;
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

    private function reportError(Throwable $error, mixed $event): void
    {
        $httpEvent = new HttpRequestEvent($event);

        $laravel = null;
        if (LaravelBridge::isInstalled()) {
            $laravel = LaravelBridge::bootLaravel();
        }

        $hint = new EventHint;
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
