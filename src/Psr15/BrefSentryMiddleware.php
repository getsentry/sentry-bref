<?php

declare(strict_types=1);

namespace Sentry\Bref\Psr15;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\Bref\BrefSpans;
use Sentry\Bref\CurrentScope;

/**
 * Middleware that loads the cold start span (if there is one) into the current Sentry transaction.
 *
 * This middleware should be added to the application's middleware stack.
 */
class BrefSentryMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $transaction = CurrentScope::getTransaction();

        if ($transaction) {
            BrefSpans::loadBrefSpans($transaction);
        }

        return $handler->handle($request);
    }
}
