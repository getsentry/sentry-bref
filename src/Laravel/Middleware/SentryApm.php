<?php declare(strict_types=1);

namespace Sentry\Bref\Laravel\Middleware;

use Sentry\Bref\BrefSpans;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SentryApm
{
    public function __construct(
        private Application $app,
    ) {
    }

    /**
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->app->bound(HubInterface::class)) {
            $transaction = SentrySdk::getCurrentHub()->getTransaction();

            if ($transaction) {
                BrefSpans::loadBrefSpans($transaction);
            }
        }

        return $next($request);
    }
}
