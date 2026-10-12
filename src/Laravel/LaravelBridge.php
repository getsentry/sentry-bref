<?php

declare(strict_types=1);

namespace Sentry\Bref\Laravel;

use Bref\LaravelBridge\LaravelPathFinder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\Kernel;

class LaravelBridge
{
    public static function isInstalled(): bool
    {
        return class_exists(Application::class) && class_exists(LaravelPathFinder::class);
    }

    public static function bootLaravel(): ?Application
    {
        if (!self::isInstalled()) {
            throw new \RuntimeException('Laravel is not installed.');
        }

        // See \Bref\LaravelBridge\HandlerResolver::laravel()

        $bootstrapFile = LaravelPathFinder::app();

        $laravel = require $bootstrapFile;
        if (!$laravel instanceof Application) {
            return null;
        }

        $kernel = $laravel->make(Kernel::class);
        $kernel->bootstrap();

        return $laravel;
    }
}
