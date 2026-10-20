<?php

declare(strict_types=1);

namespace Sentry\Bref\AwsSdk;

use Aws\AwsClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\ResultInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface; // phpcs:ignore
use Psr\Http\Message\UriInterface;
use Sentry\Breadcrumb;
use Sentry\Bref\InternalErrors;
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
        $middleware = static function (callable $handler) use ($awsClient) {
            return static function (
                CommandInterface $command,
                ?RequestInterface $request = null,
            ) use (
                $handler,
                $awsClient,
            ) {
                // Tracing must never break the AWS call
                $call = null;
                $span = null;
                try {
                    // e.g. `SQS`, `DynamoDB`, `S3`
                    $serviceId = $awsClient->getApi()->getServiceId();
                    $call = [
                        // e.g. `SQS.SendMessage`
                        'operation' => $serviceId . '.' . $command->getName(),
                        // From the API definition: the request is not built yet at this step
                        'method' => $awsClient->getApi()->getOperation($command->getName())->getHttp()['method'] ?? null,
                        'resource' => self::resourceData($serviceId, $command),
                    ];

                    $parent = SentrySdk::getCurrentHub()->getSpan();
                    // Check if we have a parent span (this is the case if we started a transaction earlier)
                    if ($parent !== null) {
                        $context = new SpanContext();
                        $context->setOp('http.client');
                        $context->setOrigin('auto.http.aws_sdk');
                        $context->setDescription($call['operation']);
                        // See https://getsentry.github.io/sentry-conventions/attributes/
                        $context->setData([
                            'rpc.system.name' => 'aws-api',
                            'rpc.service' => $serviceId,
                            'rpc.method' => $command->getName(),
                            'cloud.region' => $awsClient->getRegion(),
                            'http.request.method' => $call['method'],
                        ] + $call['resource']);
                        // The span is not set as the hub's current span: with concurrent calls
                        // (`*Async()` methods, command pools), the calls would nest in each other
                        $span = $parent->startChild($context);
                    }
                } catch (\Throwable $e) {
                    InternalErrors::log($e);
                }

                /** @var PromiseInterface $promise */
                $promise = $handler($command, $request);

                $promise->then(static function ($result) use ($call, $span) {
                    try {
                        // Finish the span first so that its duration only covers the AWS call
                        $span?->finish();
                        // The SDK copies the response status code and headers (lowercased) into `@metadata`
                        $metadata = $result instanceof ResultInterface ? ($result['@metadata'] ?? []) : [];
                        $headers = $metadata['headers'] ?? [];
                        self::recordResponse(
                            $call,
                            $span,
                            $metadata['effectiveUri'] ?? null,
                            $metadata['statusCode'] ?? null,
                            // `x-amz-request-id` is used by S3
                            $headers['x-amzn-requestid'] ?? $headers['x-amz-request-id'] ?? null,
                            $headers['x-amz-id-2'] ?? null,
                            false,
                        );
                        // Secrets are often read by name: the ARN is only known from the response
                        if ($span !== null && ($span->getData()['rpc.service'] ?? null) === 'Secrets Manager' && \is_string($result['ARN'] ?? null)) {
                            $span->setData(['aws.secretsmanager.secret.arn' => $result['ARN']]);
                        }
                    } catch (\Throwable $e) {
                        InternalErrors::log($e);
                    }

                    return $result;
                }, static function ($reason) use ($call, $span): void {
                    try {
                        // Finish the span first so that its duration only covers the AWS call
                        $span?->finish();
                        $isAwsException = $reason instanceof AwsException;
                        self::recordResponse(
                            $call,
                            $span,
                            $isAwsException ? $reason->getRequest()?->getUri() : null,
                            $isAwsException ? $reason->getStatusCode() : null,
                            $isAwsException ? $reason->getAwsRequestId() : null,
                            $isAwsException ? ($reason->getResponse()?->getHeaderLine('x-amz-id-2') ?: null) : null,
                            true,
                        );
                    } catch (\Throwable $e) {
                        InternalErrors::log($e);
                    }
                    throw $reason;
                });

                return $promise;
            };
        };

        $awsClient->getHandlerList()->appendInit($middleware);
    }

    /**
     * The AWS resource the call is about, read from the command parameters.
     *
     * @see https://getsentry.github.io/sentry-conventions/attributes/aws/
     *
     * @return array<string, mixed>
     */
    private static function resourceData(string $serviceId, CommandInterface $command): array
    {
        switch ($serviceId) {
            case 'SQS':
                // e.g. https://sqs.eu-west-3.amazonaws.com/123456789012/my-queue
                $queueUrl = $command['QueueUrl'] ?? null;

                return \is_string($queueUrl) ? ['aws.sqs.queue.url' => $queueUrl] : [];
            case 'SNS':
                $topicArn = $command['TopicArn'] ?? $command['TargetArn'] ?? null;

                return \is_string($topicArn) ? ['aws.sns.topic.arn' => $topicArn] : [];
            case 'DynamoDB':
                // Batch operations (`BatchGetItem`, `BatchWriteItem`) can target several tables
                $tableNames = \is_string($command['TableName'] ?? null)
                    ? [$command['TableName']]
                    : array_keys(\is_array($command['RequestItems'] ?? null) ? $command['RequestItems'] : []);

                return $tableNames !== [] ? ['aws.dynamodb.table_names' => $tableNames] : [];
            case 'S3':
                $bucket = $command['Bucket'] ?? null;

                return \is_string($bucket) ? ['aws.s3.bucket' => $bucket] : [];
            default:
                return [];
        }
    }

    /**
     * The URL is the one actually called, after the SDK resolved the endpoint (e.g. S3 buckets
     * as subdomains). The request IDs are what AWS support asks for when investigating a call.
     *
     * @see https://getsentry.github.io/sentry-conventions/attributes/
     *
     * @param array{operation: string, method: mixed, resource: array<string, mixed>}|null $call
     */
    private static function recordResponse(
        ?array $call,
        ?Span $span,
        UriInterface|string|null $uri,
        ?int $statusCode,
        ?string $requestId,
        ?string $extendedRequestId,
        bool $failed,
    ): void {
        $host = $uri !== null ? parse_url((string) $uri, \PHP_URL_HOST) : null;
        // Like the HTTP integrations of the Sentry SDK, the full URL is only collected
        // when the SDK's `data_collection` option allows it
        $fullUrl = $uri !== null
            ? HttpUrlCollector::collect(DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub()), HttpMessageType::outgoingRequest(), $uri)
            : null;

        if ($span !== null) {
            $span->setData(array_filter([
                'server.address' => \is_string($host) ? $host : null,
                // Sentry builds the span name from it, e.g. `POST sqs.eu-west-3.amazonaws.com`
                'url.domain' => \is_string($host) ? $host : null,
                'url.full' => $fullUrl,
                'http.response.status_code' => $statusCode,
                'aws.request_id' => $requestId,
                // Only set by S3
                'aws.extended_request_id' => $extendedRequestId,
            ], static fn ($value) => $value !== null && $value !== ''));
            $span->setStatus(match (true) {
                $statusCode !== null => SpanStatus::createFromHttpStatusCode($statusCode),
                $failed => SpanStatus::internalError(),
                default => SpanStatus::ok(),
            });
        }

        // Breadcrumbs are recorded with or without tracing: they show the AWS calls that led to an error
        if ($call !== null) {
            \Sentry\addBreadcrumb(new Breadcrumb(
                match (true) {
                    $statusCode !== null && $statusCode >= 500, $statusCode === null && $failed => Breadcrumb::LEVEL_ERROR,
                    $statusCode !== null && $statusCode >= 400 => Breadcrumb::LEVEL_WARNING,
                    default => Breadcrumb::LEVEL_INFO,
                },
                Breadcrumb::TYPE_HTTP,
                'http',
                $call['operation'],
                // `url`, `method` and `status_code` are the keys Sentry displays for HTTP breadcrumbs
                array_filter([
                    // Like the Guzzle integration of the Sentry SDK: without the query string unless allowed
                    'url' => $fullUrl ?? ($uri !== null ? (string) (new Uri((string) $uri))->withUserInfo('')->withQuery('')->withFragment('') : null),
                    'method' => $call['method'],
                    'status_code' => $statusCode,
                    'aws.request_id' => $requestId,
                ], static fn ($value) => $value !== null && $value !== '') + $call['resource'],
            ));
        }
    }
}
