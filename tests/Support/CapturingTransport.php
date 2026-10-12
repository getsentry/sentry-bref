<?php

declare(strict_types=1);

namespace Sentry\Bref\Test\Support;

use Sentry\Event;
use Sentry\EventType;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * Keeps the events the SDK would send to Sentry, so that tests can assert on them.
 */
class CapturingTransport implements TransportInterface
{
    /**
     * @var Event[]
     */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }

    /**
     * @return Event[]
     */
    public function transactions(): array
    {
        return array_values(array_filter($this->events, static fn (Event $event) => $event->getType() === EventType::transaction()));
    }

    /**
     * @return Event[]
     */
    public function errors(): array
    {
        return array_values(array_filter($this->events, static fn (Event $event) => $event->getType() === EventType::event()));
    }
}
