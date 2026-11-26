<?php

declare(strict_types=1);

namespace Sentry\Bref\Laravel\Queue;

use Illuminate\Queue\Connectors\SqsConnector;
use Illuminate\Queue\SqsQueue;
use Sentry\Bref\AwsSdk\AwsSdkTracer;
use Sentry\Bref\InternalErrors;

/**
 * Laravel's own SQS connector, with the client of the queue traced: the jobs it sends, and the
 * messages the worker deletes or releases.
 *
 * @internal
 */
class TracedSqsConnector extends SqsConnector
{
    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config)
    {
        $queue = parent::connect($config);
        try {
            if ($queue instanceof SqsQueue) {
                AwsSdkTracer::trace($queue->getSqs());
            }
        } catch (\Throwable $e) {
            InternalErrors::log($e);
        }

        return $queue;
    }
}
