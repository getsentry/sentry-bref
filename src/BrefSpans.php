<?php declare(strict_types=1);

namespace Sentry\Bref;

use Bref\Logger\StderrLogger;
use JsonException;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;

/**
 * @internal
 */
class BrefSpans
{
    private const SPANS_FILE = '/tmp/bref-sentry-spans.json';

    public static function storeSpan(string $id, string $description, float $start, float $end): void
    {
        $span = [
            'id' => $id,
            'description' => $description,
            'start' => $start,
            'end' => $end,
        ];
        // This is written to a file so that even the FPM worker can report it
        file_put_contents(self::SPANS_FILE, json_encode($span) . PHP_EOL, FILE_APPEND);
        // Make sure the file is writeable by other users (can happen when running in Docker)
        chmod(self::SPANS_FILE, 0666);
    }

    public static function loadBrefSpans(Transaction $transaction): void
    {
        if (! file_exists(self::SPANS_FILE)) {
            return;
        }

        foreach (file(self::SPANS_FILE) as $line) {
            try {
                $span = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $logger = new StderrLogger;
                $logger->warning('Internal Bref error: failed to decode JSON from the Sentry cold start tracking: ' . $line);

                // ignore
                continue;
            }

            $spanContext = new SpanContext;
            $spanContext->setOp($span['id']);
            $spanContext->setDescription($span['description']);
            $spanContext->setStartTimestamp($span['start']);
            $spanContext->setEndTimestamp($span['end']);

            $transaction->startChild($spanContext);

            // Offset the start time of the transaction to the earliest span
            $startTimestamp = $span['start'];
            if ($startTimestamp < $transaction->getStartTimestamp()) {
                $transaction->setStartTimestamp($startTimestamp);
            }
        }

        file_put_contents(self::SPANS_FILE, '');
        // Make sure the file is writeable by other users (can happen when running in Docker)
        chmod(self::SPANS_FILE, 0666);
    }
}
