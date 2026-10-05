<?php declare(strict_types=1);

namespace Sentry\Bref\Test\Psr15;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\Bref\BrefSpans;
use Sentry\Bref\Psr15\BrefSentryMiddleware;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

class BrefSentryMiddlewareTest extends TestCase
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

    public function testLoadsBrefSpansIntoTheCurrentTransaction(): void
    {
        $hub = SentrySdk::init();
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', microtime(true) - 1, microtime(true));

        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($response);

        $result = (new BrefSentryMiddleware)->process($this->createMock(ServerRequestInterface::class), $handler);

        $this->assertSame($response, $result);
        $ops = array_map(fn (Span $span) => $span->getOp(), $transaction->getSpanRecorder()?->getSpans() ?? []);
        $this->assertContains('lambda.cold-start', $ops);
    }

    public function testCallsTheNextHandlerWithoutATransaction(): void
    {
        SentrySdk::init();

        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($response);

        $result = (new BrefSentryMiddleware)->process($this->createMock(ServerRequestInterface::class), $handler);

        $this->assertSame($response, $result);
    }
}
