<?php declare(strict_types=1);

use Bref\Bref;
use Sentry\Bref\Bref\BrefSubscriber;

Bref::events()->subscribe(new BrefSubscriber);
