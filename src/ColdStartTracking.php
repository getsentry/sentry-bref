<?php declare(strict_types=1);

namespace Sentry\Bref;

/**
 * @internal
 */
class ColdStartTracking
{
    private const FILE = '/tmp/bref-sentry-cold-start';

    private static bool $isColdStart;
    private static float $coldStartTime;

    public static function init(): void
    {
        self::$coldStartTime = microtime(true);

        self::$isColdStart = ! file_exists(self::FILE);
        if (self::$isColdStart) {
            touch(self::FILE);
        }
    }

    public static function getColdStartTime(): float
    {
        return self::$coldStartTime;
    }

    public static function isColdStart(): bool
    {
        return self::$isColdStart;
    }

    public static function coldStartFinished(): void
    {
        self::$isColdStart = false;
    }
}
