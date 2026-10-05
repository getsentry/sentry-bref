<?php declare(strict_types=1);

namespace Sentry\Bref\Test\Symfony;

use PHPUnit\Framework\TestCase;
use Sentry\Bref\BrefSpans;
use Sentry\Bref\Symfony\EventSubscriber;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class EventSubscriberTest extends TestCase
{
    private const SPANS_FILE = '/tmp/bref-sentry-spans.json';

    protected function setUp(): void
    {
        if (file_exists(self::SPANS_FILE)) {
            unlink(self::SPANS_FILE);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists(self::SPANS_FILE)) {
            unlink(self::SPANS_FILE);
        }
    }

    public function testRunsAfterTheSentryBundle(): void
    {
        $this->assertSame([
            RequestEvent::class => ['onKernelRequest', -10],
        ], EventSubscriber::getSubscribedEvents());
    }

    public function testLoadsBrefSpansOnTheMainRequest(): void
    {
        $transaction = $this->startTransaction();
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', microtime(true) - 1, microtime(true));

        (new EventSubscriber)->onKernelRequest($this->createRequestEvent(HttpKernelInterface::MAIN_REQUEST));

        $spans = $this->childSpans($transaction);
        $this->assertCount(1, $spans);
        $this->assertSame('lambda.cold-start', $spans[0]->getOp());
    }

    public function testIgnoresSubRequests(): void
    {
        $transaction = $this->startTransaction();
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', microtime(true) - 1, microtime(true));

        (new EventSubscriber)->onKernelRequest($this->createRequestEvent(HttpKernelInterface::SUB_REQUEST));

        $this->assertSame([], $this->childSpans($transaction));
    }

    private function startTransaction(): Transaction
    {
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        SentrySdk::init()->setSpan($transaction);

        return $transaction;
    }

    private function createRequestEvent(int $requestType): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), new Request, $requestType);
    }

    /**
     * @return Span[]
     */
    private function childSpans(Transaction $transaction): array
    {
        $spans = $transaction->getSpanRecorder()?->getSpans() ?? [];

        return array_values(array_filter($spans, fn (Span $span) => $span !== $transaction));
    }
}
