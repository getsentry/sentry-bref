<?php declare(strict_types=1);

namespace Sentry\Bref\AwsSdk;

use Aws\AwsClient;
use Aws\CommandInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface; // phpcs:ignore
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

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
                    $context = new SpanContext;
                    $clientClassName = $awsClient::class;
                    // Remove the namespace
                    $clientClassName = substr($clientClassName, strrpos($clientClassName, '\\') + 1);
                    // Remove the "Client" suffix
                    $clientName = substr($clientClassName, 0, -6);
                    $context->setOp('aws.sdk.' . strtolower($clientName));
                    $context->setDescription($clientClassName . ' ' . $command->getName());
                    $context->setData([
                        'aws.sdk.client' => $clientClassName,
                        'aws.sdk.command' => $command->getName(),
                        'aws.sdk.region' => $awsClient->getRegion(),
                        'aws.sdk.endpoint' => (string) $awsClient->getEndpoint(),
                    ]);
                    $span = $parent->startChild($context);

                    // Set the current span to the span we just started
                    $hub->setSpan($span);
                }

                /** @var PromiseInterface $promise */
                $promise = $handler($command, $request);

                $promise->then(static function ($result) use ($parent, $hub, $span) {
                    // We only have a span if we started a span earlier
                    if ($span !== null) {
                        $span->finish();
                        // Restore the current span back to the parent span
                        $hub->setSpan($parent);
                    }
                    return $result;
                }, static function ($reason) use ($parent, $hub, $span): void {
                    // We only have a span if we started a span earlier
                    if ($span !== null) {
                        $span->finish();
                        // Restore the current span back to the parent span
                        $hub->setSpan($parent);
                    }
                    throw $reason;
                });

                return $promise;
            };
        };

        $awsClient->getHandlerList()->appendInit($middleware);
    }
}
