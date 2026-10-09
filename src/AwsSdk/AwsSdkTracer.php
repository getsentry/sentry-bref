<?php declare(strict_types=1);

namespace Sentry\Bref\AwsSdk;

use Aws\AwsClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\ResultInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface; // phpcs:ignore
use Psr\Http\Message\UriInterface;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpMessageType;
use Sentry\DataCollection\HttpUrlCollector;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
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
                        // From the API definition: the request is not built yet at this step
                        'http.request.method' => $awsClient->getApi()->getOperation($command->getName())->getHttp()['method'] ?? null,
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
                        // Finish the span first so that its duration only covers the AWS call
                        $span->finish();
                        // The SDK copies the response status code and headers (lowercased) into `@metadata`
                        $metadata = $result instanceof ResultInterface ? ($result['@metadata'] ?? []) : [];
                        $headers = $metadata['headers'] ?? [];
                        self::recordResponse(
                            $span,
                            $metadata['effectiveUri'] ?? null,
                            $metadata['statusCode'] ?? null,
                            // `x-amz-request-id` is used by S3
                            $headers['x-amzn-requestid'] ?? $headers['x-amz-request-id'] ?? null,
                            $headers['x-amz-id-2'] ?? null,
                        );
                        $span->setStatus(isset($metadata['statusCode']) ? SpanStatus::createFromHttpStatusCode($metadata['statusCode']) : SpanStatus::ok());
                    }
                    return $result;
                }, static function ($reason) use ($span): void {
                    // We only have a span if we started a span earlier
                    if ($span !== null) {
                        // Finish the span first so that its duration only covers the AWS call
                        $span->finish();
                        $statusCode = null;
                        if ($reason instanceof AwsException) {
                            $statusCode = $reason->getStatusCode();
                            self::recordResponse(
                                $span,
                                $reason->getRequest()?->getUri(),
                                $statusCode,
                                $reason->getAwsRequestId(),
                                $reason->getResponse()?->getHeaderLine('x-amz-id-2') ?: null,
                            );
                        }
                        $span->setStatus($statusCode !== null ? SpanStatus::createFromHttpStatusCode($statusCode) : SpanStatus::internalError());
                    }
                    throw $reason;
                });

                return $promise;
            };
        };

        $awsClient->getHandlerList()->appendInit($middleware);
    }

    /**
     * The URL is the one actually called, after the SDK resolved the endpoint (e.g. S3 buckets
     * as subdomains). The request IDs are what AWS support asks for when investigating a call.
     *
     * @see https://getsentry.github.io/sentry-conventions/attributes/
     */
    private static function recordResponse(
        Span $span,
        UriInterface|string|null $uri,
        ?int $statusCode,
        ?string $requestId,
        ?string $extendedRequestId,
    ): void {
        $host = $uri !== null ? parse_url((string) $uri, PHP_URL_HOST) : null;
        // Like the HTTP integrations of the Sentry SDK, the full URL is only collected
        // when the SDK's `data_collection` option allows it
        $fullUrl = $uri !== null
            ? HttpUrlCollector::collect(DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub()), HttpMessageType::outgoingRequest(), $uri)
            : null;

        $span->setData(array_filter([
            'server.address' => is_string($host) ? $host : null,
            // Sentry builds the span name from it, e.g. `POST sqs.eu-west-3.amazonaws.com`
            'url.domain' => is_string($host) ? $host : null,
            'url.full' => $fullUrl,
            'http.response.status_code' => $statusCode,
            'aws.request_id' => $requestId,
            // Only set by S3
            'aws.extended_request_id' => $extendedRequestId,
        ], fn ($value) => $value !== null && $value !== ''));
    }
}
