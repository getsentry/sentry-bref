<?php

declare(strict_types=1);

namespace Sentry\Bref\Laravel;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Sentry\Bref\AwsSdk\AwsSdkTracer;
use Sentry\Bref\BrefSpans;
use Sentry\Bref\InternalErrors;
use Sentry\Bref\Laravel\Middleware\SentryApm;
use Sentry\Bref\Laravel\Queue\TracedSqsConnector;
use Sentry\SentrySdk;

class BrefSentryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->registerHttpMiddleware();
        $this->traceAwsClientsOfLaravel();

        // Once all providers are booted, so that this listener runs after sentry-laravel's,
        // which creates the job's transaction
        $this->app->booted(function () {
            $this->app['events']->listen(JobProcessing::class, static function (): void {
                $transaction = SentrySdk::getCurrentHub()->getTransaction();
                if ($transaction) {
                    // e.g. the cold start, when the job is the first one processed by the Lambda instance
                    BrefSpans::loadBrefSpans($transaction);
                }
            });
        });
    }

    /**
     * The AWS SDK clients that Laravel creates for SQS queues and S3 disks.
     */
    private function traceAwsClientsOfLaravel(): void
    {
        $this->callAfterResolving('queue', static function (QueueManager $queue): void {
            $queue->addConnector('sqs', static fn () => new TracedSqsConnector());
        });

        $this->callAfterResolving('filesystem', function (FilesystemManager $filesystem): void {
            $filesystem->extend('s3', $this->createS3Disk(...));
        });
    }

    /**
     * Laravel's own S3 driver, with its client traced.
     *
     * @param array<string, mixed> $config
     */
    private function createS3Disk(Application $app, array $config): Filesystem
    {
        $disk = $app->make(FilesystemManager::class)->createS3Driver($config);
        try {
            if ($disk instanceof AwsS3V3Adapter) {
                AwsSdkTracer::trace($disk->getClient());
            }
        } catch (\Throwable $e) {
            InternalErrors::log($e);
        }

        return $disk;
    }

    private function registerHttpMiddleware(): void
    {
        // @phpstan-ignore-next-line
        if ($this->app instanceof \Laravel\Lumen\Application) { // phpcs:ignore
            // @phpstan-ignore-next-line
            $this->app->middleware(SentryApm::class);
        } elseif ($this->app->bound(HttpKernelInterface::class)) {
            $httpKernel = $this->app->make(HttpKernelInterface::class);
            if ($httpKernel instanceof \Illuminate\Foundation\Http\Kernel) { // phpcs:ignore
                $httpKernel->pushMiddleware(SentryApm::class);
            }
        }
    }
}
