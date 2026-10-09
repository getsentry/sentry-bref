<?php declare(strict_types=1);

namespace Sentry\Bref\AwsSdk;

use Aws\AwsClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface; // phpcs:ignore
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

abstract class AwsSdkTracer
{
    public static function trace(AwsClient $awsClient): void
    {
        $middleware = function (callable $handler) use ($awsClient) {
            return static function (
                CommandInterface $command,
                RequestInterface | null $request = null,
            ) use (
                $handler,
                $awsClient,
            ) {
                $hub = SentrySdk::getCurrentHub();
                $parent = $hub->getSpan();
                $span = null;

                // Check if we have a parent span (this is the case if we started a transaction earlier)
                if ($parent !== null) {
                    // e.g. `SQS`, `DynamoDB`, `S3`
                    $serviceId = $awsClient->getApi()->getServiceId();
                    $context = new SpanContext;
                    $context->setOp('http.client');
                    $context->setOrigin('auto.http.aws_sdk');
                    $context->setDescription($serviceId . '.' . $command->getName());
                    // See https://getsentry.github.io/sentry-conventions/attributes/
                    $context->setData([
                        'rpc.system.name' => 'aws-api',
                        'rpc.service' => $serviceId,
                        'rpc.method' => $command->getName(),
                        'cloud.region' => $awsClient->getRegion(),
                    ]);
                    // The span is not set as the hub's current span: with concurrent calls
                    // (`*Async()` methods, command pools), the calls would nest in each other
                    $span = $parent->startChild($context);
                }

                /** @var PromiseInterface $promise */
                $promise = $handler($command, $request);

                $promise->then(static function ($result) use ($span) {
                    // We only have a span if we started a span earlier
                    if ($span !== null) {
                        $span->setStatus(SpanStatus::ok());
                        $span->finish();
                    }
                    return $result;
                }, static function ($reason) use ($span): void {
                    // We only have a span if we started a span earlier
                    if ($span !== null) {
                        $statusCode = $reason instanceof AwsException ? $reason->getStatusCode() : null;
                        $span->setStatus($statusCode !== null ? SpanStatus::createFromHttpStatusCode($statusCode) : SpanStatus::internalError());
                        $span->finish();
                    }
                    throw $reason;
                });

                return $promise;
            };
        };

        $awsClient->getHandlerList()->appendInit($middleware);
    }
}
