<?php declare(strict_types=1);

namespace Sentry\Bref\Test;

use PHPUnit\Framework\TestCase;
use Sentry\Bref\ColdStartTracking;

class ColdStartTrackingTest extends TestCase
{
    private const FILE = '/tmp/bref-sentry-cold-start';

    protected function setUp(): void
    {
        if (file_exists(self::FILE)) {
            unlink(self::FILE);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists(self::FILE)) {
            unlink(self::FILE);
        }
    }

    public function testFirstInitializationIsAColdStart(): void
    {
        $before = microtime(true);
        ColdStartTracking::init();

        $this->assertTrue(ColdStartTracking::isColdStart());
        $this->assertGreaterThanOrEqual($before, ColdStartTracking::getColdStartTime());
        $this->assertFileExists(self::FILE);
    }

    public function testLaterInitializationsAreNotColdStarts(): void
    {
        ColdStartTracking::init();
        // For example, the PHP process restarted between invocations
        ColdStartTracking::init();

        $this->assertFalse(ColdStartTracking::isColdStart());
    }

    public function testColdStartFinished(): void
    {
        ColdStartTracking::init();
        ColdStartTracking::coldStartFinished();

        $this->assertFalse(ColdStartTracking::isColdStart());
    }
}
