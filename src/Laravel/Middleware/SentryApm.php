<?php declare(strict_types=1);

namespace Sentry\Bref\Laravel\Middleware;

use Sentry\Bref\BrefSpans;
use Sentry\Bref\InternalErrors;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
                self::repairSpansEndingBeforeTheyStart($transaction);
            }
        }

        return $next($request);
    }

    /**
     * sentry-laravel's `app.bootstrap` span goes from the start of the request to the end of
     * the Laravel boot. With Octane, Laravel boots during the cold start, before the request:
     * the span ends before it starts, and Sentry drops the whole transaction as invalid.
     * The boot is already in the cold start spans: the span is reduced to an instant.
     */
    private static function repairSpansEndingBeforeTheyStart(Transaction $transaction): void
    {
        try {
            foreach ($transaction->getSpanRecorder()?->getSpans() ?? [] as $span) {
                $end = $span->getEndTimestamp();
                if ($end !== null && $end < $span->getStartTimestamp()) {
                    $span->setStartTimestamp($end);
                }
            }
        } catch (Throwable $e) {
            InternalErrors::log($e);
        }
    }
}
