<?php declare(strict_types=1);

namespace Sentry\Bref\Test;

use PHPUnit\Framework\TestCase;
use Sentry\Bref\BrefSpans;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

class BrefSpansTest extends TestCase
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

    public function testDoesNothingWhenNoSpanWasStored(): void
    {
        $transaction = $this->createTransaction();
        $startTimestamp = $transaction->getStartTimestamp();

        BrefSpans::loadBrefSpans($transaction);

        $this->assertSame([], $this->childSpans($transaction));
        $this->assertSame($startTimestamp, $transaction->getStartTimestamp());
    }

    public function testStoredSpansAreAddedToTheTransaction(): void
    {
        $now = microtime(true);
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', $now - 10, $now - 2);
        BrefSpans::storeSpan('bref.startup', 'Bref startup', $now - 8, $now - 5);

        $transaction = $this->createTransaction();
        BrefSpans::loadBrefSpans($transaction);

        $spans = $this->childSpans($transaction);
        $this->assertCount(2, $spans);
        $this->assertSame('lambda.cold-start', $spans[0]->getOp());
        $this->assertSame('Lambda cold start', $spans[0]->getDescription());
        $this->assertSame($now - 10, $spans[0]->getStartTimestamp());
        $this->assertSame($now - 2, $spans[0]->getEndTimestamp());
        $this->assertSame('bref.startup', $spans[1]->getOp());
        $this->assertSame('Bref startup', $spans[1]->getDescription());
        $this->assertSame($now - 8, $spans[1]->getStartTimestamp());
        $this->assertSame($now - 5, $spans[1]->getEndTimestamp());
        // The transaction starts with the earliest span
        $this->assertSame($now - 10, $transaction->getStartTimestamp());
    }

    public function testStoredSpansAreLoadedOnlyOnce(): void
    {
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', microtime(true) - 1, microtime(true));
        BrefSpans::loadBrefSpans($this->createTransaction());

        $transaction = $this->createTransaction();
        BrefSpans::loadBrefSpans($transaction);

        $this->assertSame([], $this->childSpans($transaction));
    }

    public function testInvalidLinesAreSkipped(): void
    {
        file_put_contents(self::SPANS_FILE, 'not JSON' . PHP_EOL);
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', microtime(true) - 1, microtime(true));

        $transaction = $this->createTransaction();
        BrefSpans::loadBrefSpans($transaction);

        $spans = $this->childSpans($transaction);
        $this->assertCount(1, $spans);
        $this->assertSame('lambda.cold-start', $spans[0]->getOp());
    }

    public function testNeverBreaksTheRequest(): void
    {
        BrefSpans::storeSpan('lambda.cold-start', 'Lambda cold start', microtime(true) - 1, microtime(true));
        // e.g. a file created by another user (can happen when running in Docker)
        chmod(self::SPANS_FILE, 0000);
        $previousErrorLog = ini_set('error_log', '/dev/null');

        try {
            BrefSpans::loadBrefSpans($this->createTransaction());
        } finally {
            ini_set('error_log', (string) $previousErrorLog);
            chmod(self::SPANS_FILE, 0666);
        }

        $this->addToAssertionCount(1);
    }

    private function createTransaction(): Transaction
    {
        $transaction = new Transaction(new TransactionContext);
        $transaction->initSpanRecorder();

        return $transaction;
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
