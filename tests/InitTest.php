<?php declare(strict_types=1);

namespace Sentry\Bref\Test;

use Bref\Bref;
use PHPUnit\Framework\TestCase;

class InitTest extends TestCase
{
    private const COLD_START_FILE = '/tmp/bref-sentry-cold-start';

    protected function setUp(): void
    {
        if (file_exists(self::COLD_START_FILE)) {
            unlink(self::COLD_START_FILE);
        }
    }

    protected function tearDown(): void
    {
        unset($_SERVER['BREF_RUNTIME']);
        Bref::reset();
        if (file_exists(self::COLD_START_FILE)) {
            unlink(self::COLD_START_FILE);
        }
    }

    public function testDoesNothingOutsideTheBrefRuntime(): void
    {
        require __DIR__ . '/../src/init.php';

        // The subscriber, which tracks cold starts with that file, was not created
        $this->assertFileDoesNotExist(self::COLD_START_FILE);
    }

    public function testSubscribesInTheBrefRuntime(): void
    {
        $_SERVER['BREF_RUNTIME'] = 'Bref\FunctionRuntime\Main';

        require __DIR__ . '/../src/init.php';

        $this->assertFileExists(self::COLD_START_FILE);
    }
}
