<?php declare(strict_types=1);

namespace Sentry\Bref\Test;

use PHPUnit\Framework\TestCase;
use Sentry\Bref\StartupTracking;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

class StartupTrackingTest extends TestCase
{
    private const FILE = '/tmp/bref-sentry-startup.txt';

    protected function setUp(): void
    {
        SentrySdk::init();
        StartupTracking::clear();
    }

    protected function tearDown(): void
    {
        StartupTracking::clear();
    }

    public function testNoChildTransactionWithoutAStoredReference(): void
    {
        $this->assertNull(StartupTracking::createChildTransaction());
    }

    public function testChildTransactionContinuesTheStoredTransaction(): void
    {
        $hub = SentrySdk::getCurrentHub();
        $hub->bindClient(ClientBuilder::create([
            'dsn' => 'https://public@example.com/1',
            'traces_sample_rate' => 1.0,
        ])->getClient());
        $transaction = new Transaction(new TransactionContext);
        $transaction->setSampled(true);
        $hub->setSpan($transaction);

        StartupTracking::storeReferenceToCurrentTransaction();
        $context = StartupTracking::createChildTransaction();

        $this->assertNotNull($context);
        $this->assertEquals($transaction->getTraceId(), $context->getTraceId());
        $this->assertEquals($transaction->getSpanId(), $context->getParentSpanId());
        $this->assertTrue($context->getParentSampled());
    }

    public function testClearRemovesTheStoredReference(): void
    {
        StartupTracking::storeReferenceToCurrentTransaction();
        StartupTracking::clear();

        $this->assertFileDoesNotExist(self::FILE);
        $this->assertNull(StartupTracking::createChildTransaction());
    }
}
