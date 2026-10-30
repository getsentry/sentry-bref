<?php

declare(strict_types=1);

namespace Sentry\Bref;

use Bref\Context\Context;
use Sentry\Event;
use Sentry\State\Scope;

/**
 * Adds the Lambda context of the invocation in progress to the events.
 *
 * Added to the events rather than set on the scope: frameworks juggle with scopes (sentry-laravel
 * keeps the scope of a failed job until the next job), a scope can hold a previous invocation.
 *
 * @internal
 */
class LambdaContext
{
    private static ?Context $invocation = null;
    private static bool $eventProcessorRegistered = false;

    public static function start(Context $context): void
    {
        self::$invocation = $context;
        if (self::$eventProcessorRegistered) {
            return;
        }

        // Global: applies to the events of every hub and scope
        Scope::addGlobalEventProcessor(static function (Event $event): Event {
            $context = self::$invocation;
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

    /**
     * PHP-FPM requests and the commands of the console runtime run in a separate process,
     * inside one invocation: Bref passes them its context.
     */
    public static function startFromEnvironment(): void
    {
        // Runs in every request: it must never break the request
        try {
            $json = $_SERVER['LAMBDA_INVOCATION_CONTEXT'] ?? null;
            if (!\is_string($json)) {
                return;
            }

            $context = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            if (
                !\is_array($context)
                || !\is_string($context['awsRequestId'] ?? null)
                || !\is_int($context['deadlineMs'] ?? null)
                || !\is_string($context['invokedFunctionArn'] ?? null)
                || !\is_string($context['traceId'] ?? null)
            ) {
                return;
            }

            self::start(new Context($context['awsRequestId'], $context['deadlineMs'], $context['invokedFunctionArn'], $context['traceId']));
        } catch (\Throwable $e) {
            InternalErrors::log($e);
        }
    }

    public static function end(): void
    {
        self::$invocation = null;
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
}
