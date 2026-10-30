<p align="center">
  <a href="https://sentry.io/?utm_source=github&utm_medium=logo" target="_blank">
    <picture>
      <source srcset="https://sentry-brand.storage.googleapis.com/sentry-logo-white.png" media="(prefers-color-scheme: dark)" />
      <source srcset="https://sentry-brand.storage.googleapis.com/sentry-wordmark-dark-280x84.png" media="(prefers-color-scheme: light), (prefers-color-scheme: no-preference)" />
      <img src="https://sentry-brand.storage.googleapis.com/sentry-wordmark-dark-280x84.png" alt="Sentry" width="280">
    </picture>
  </a>
</p>

_Bad software is everywhere, and we're tired of it. Sentry is on a mission to help developers write better software faster, so we can get back to enjoying technology. If you want to join us [<kbd>**Check out our open positions**</kbd>](https://sentry.io/careers/)_

# Official Sentry integration for Bref

[![CI](https://github.com/getsentry/sentry-bref/actions/workflows/ci.yml/badge.svg)](https://github.com/getsentry/sentry-bref/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/sentry/sentry-bref/v/stable)](https://packagist.org/packages/sentry/sentry-bref)
[![License](https://poser.pugx.org/sentry/sentry-bref/license)](https://packagist.org/packages/sentry/sentry-bref)
[![Discord](https://img.shields.io/discord/621778831602221064)](https://discord.gg/cWnMQeA)

This is the official [Sentry](https://sentry.io) integration for [Bref](https://bref.sh), to monitor PHP applications running on AWS Lambda. It adds to the Sentry SDK for PHP, Laravel or Symfony:

- Lambda errors that happen outside of PHP-FPM: timeouts, responses too large, etc.
- Exceptions and transactions for event handlers (SQS, EventBridge, S3, etc.).
- Cold starts in transactions.
- AWS SDK calls as spans and breadcrumbs.

## Getting Started

### Install

First, set up Sentry in your application: [PHP](https://docs.sentry.io/platforms/php/), [Laravel](https://docs.sentry.io/platforms/php/guides/laravel/) or [Symfony](https://docs.sentry.io/platforms/php/guides/symfony/).

Then install the `sentry/sentry-bref` package:

```bash
composer require sentry/sentry-bref
```

### Configure

**Laravel**: there is nothing to configure, the service provider is discovered automatically.

**Symfony**: register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Sentry\Bref\Symfony\BrefSentryBundle::class => ['all' => true],
];
```

**Other frameworks**: add the `Sentry\Bref\Psr15\BrefSentryMiddleware` PSR-15 middleware to your HTTP middleware stack.

To learn more about how to use the integration, [refer to our docs](https://docs.sentry.io/platforms/php/guides/bref/).

## Getting help/support

If you need help setting up or configuring the Bref integration (or anything else in the Sentry universe) please head over to the [Sentry Community on Discord](https://discord.com/invite/Ww9hbqr). There is a ton of great people in our Discord community ready to help you!

## Resources

- [![Documentation](https://img.shields.io/badge/documentation-sentry.io-green.svg)](https://docs.sentry.io/platforms/php/guides/bref/)
- [![Discord](https://img.shields.io/discord/621778831602221064)](https://discord.gg/Ww9hbqr)
- [![Stack Overflow](https://img.shields.io/badge/stack%20overflow-sentry-green.svg)](http://stackoverflow.com/questions/tagged/sentry)
- [![X Follow](https://img.shields.io/twitter/follow/sentry?label=sentry&style=social)](https://x.com/intent/follow?screen_name=sentry)

## License

Licensed under the MIT license, see [`LICENSE`](LICENSE).
