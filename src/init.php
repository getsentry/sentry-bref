<?php

declare(strict_types=1);

use Bref\Bref;
use Sentry\Bref\Bref\BrefSubscriber;

// Composer loads this file in every process (local development, tests, CLI…): only
// subscribe in the Bref runtime, identified like Bref's bootstrap does
if (isset($_SERVER['BREF_RUNTIME']) || isset($_SERVER['RUNTIME_CLASS']) || getenv('BREF_RUNTIME') !== false) {
    Bref::events()->subscribe(new BrefSubscriber());
}
