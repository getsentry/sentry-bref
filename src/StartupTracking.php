<?php

declare(strict_types=1);

namespace Sentry\Bref;

use Sentry\Tracing\TransactionContext;

use function Sentry\continueTrace;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;

/**
 * @internal
 */
class StartupTracking
{
    private const FILE = '/tmp/bref-sentry-startup.txt';

    public static function storeReferenceToCurrentTransaction(): void
    {
        file_put_contents(self::FILE, json_encode([
            'baggage' => getBaggage(),
            'parentTrace' => getTraceparent(),
        ]));
    }

    public static function createChildTransaction(): ?TransactionContext
    {
        if (!file_exists(self::FILE)) {
            return null;
        }

        $json = file_get_contents(self::FILE);
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $parentTrace = $data['parentTrace'] ?? null;
        $baggage = $data['baggage'] ?? null;

        if (!$parentTrace || !$baggage) {
            return null;
        }

        return continueTrace($parentTrace, $baggage);
    }

    public static function clear(): void
    {
        if (file_exists(self::FILE)) {
            unlink(self::FILE);
        }
    }
}
