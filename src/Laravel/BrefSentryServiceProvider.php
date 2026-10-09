<?php declare(strict_types=1);

namespace Sentry\Bref\Laravel;

use Illuminate\Contracts\Http\Kernel as HttpKernelInterface;
use Illuminate\Queue\Events\JobProcessing;
use Sentry\Bref\BrefSpans;
use Sentry\Bref\Laravel\Middleware\SentryApm;
use Sentry\SentrySdk;
use Illuminate\Support\ServiceProvider;

class BrefSentryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->registerHttpMiddleware();

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
