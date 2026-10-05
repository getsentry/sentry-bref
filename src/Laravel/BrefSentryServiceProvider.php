<?php declare(strict_types=1);

namespace Sentry\Bref\Laravel;

use Sentry\Bref\Laravel\Middleware\SentryApm;
use Illuminate\Contracts\Http\Kernel as HttpKernelInterface;
use Illuminate\Support\ServiceProvider;

class BrefSentryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->registerHttpMiddleware();
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
