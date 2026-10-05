<?php declare(strict_types=1);

namespace Sentry\Bref\TracedHandler;

use Bref\Event\EventBridge\EventBridgeHandler;

/**
 * @deprecated Can be removed in favor of the native Bref EventBridgeHandler
 *
 * Sentry tracking works out of the box with the native Bref EventBridgeHandler now.
 */
abstract class TracedEventBridgeHandler extends EventBridgeHandler
{
}
