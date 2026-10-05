<?php declare(strict_types=1);

namespace Sentry\Bref\Symfony;

use Sentry\Bref\BrefSpans;
use Sentry\SentrySdk;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class EventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Low priority to run after the SentryBundle
            RequestEvent::class => ['onKernelRequest', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // To support Symfony 4
        // @phpstan-ignore-next-line
        $isMainRequest = method_exists($event, 'isMainRequest') ? $event->isMainRequest() : $event->isMasterRequest();
        if (! $isMainRequest) {
            // don't do anything if it's not the main request
            return;
        }

        $transaction = SentrySdk::getCurrentHub()->getTransaction();
        if ($transaction) {
            BrefSpans::loadBrefSpans($transaction);
        }
    }
}
