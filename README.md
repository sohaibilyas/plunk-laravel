# Plunk for Laravel

[![Tests](https://github.com/sohaibilyas/plunk-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/sohaibilyas/plunk-laravel/actions/workflows/tests.yml)

A community Laravel mail transport for hosted or self-hosted [Plunk](https://useplunk.com). Works with existing Mailables and mail notifications. Maintained independently; not an official Plunk SDK.

## Requirements

PHP 8.3+, Laravel 12 or 13, and a Plunk server that supports the `Idempotency-Key` header and retains keys for at least 24 hours. Laravel 13 requires PHP 8.3 or later. Do not install alongside another package registering the `plunk` mail transport/configuration.

## Install

```sh
composer require sohaibilyas/plunk-laravel:^3.0
php artisan vendor:publish --tag=plunk-config
```

The service provider is discovered automatically. Add this mailer to `config/mail.php`:

```php
'mailers' => [
    // Existing mailers...
    'plunk' => ['transport' => 'plunk'],
],
```

Configure your server environment:

```dotenv
MAIL_MAILER=plunk
MAIL_FROM_ADDRESS=notifications@example.com
MAIL_FROM_NAME="Your application"
PLUNK_SECRET_KEY=sk_your_secret_key
PLUNK_BASE_API_URL=https://api.useplunk.com
PLUNK_TIMEOUT=15
PLUNK_CONNECT_TIMEOUT=5
PLUNK_MAIL_MAX_JOB_AGE=82800
```

For self-hosted Plunk, set `PLUNK_BASE_API_URL` to the public HTTPS API origin. The default sending path is `/v1/send`; set `PLUNK_API_ENDPOINT` only if your installation uses another path. The sending domain must be verified in Plunk/SES. After changing configuration, rebuild your config cache and restart long-lived queue/application workers using your normal deployment process.

```php
Mail::to($user)->send(new WelcomeMail());
Mail::to($user)->queue(new InvoiceMail());
$user->notify(new AccountNotification());
```

## Duplicate-send protection

Laravel Mail and mail notifications use one request per recipient, each with a stable idempotency key. Retries of the same queued job reuse its UUID and mail position. Partial multi-recipient failures therefore reuse the accepted recipients' keys. Nested synchronous jobs inherit the outer retry identity. Separate job dispatches and independent synchronous sends are separate send intents.

The transport does not retry HTTP requests internally. Connection failures and errors are thrown to Laravel. A `409` is accepted only when Plunk reports `IDEMPOTENCY_KEY_REUSED`, the exact key matches, and the original request returned a 2xx status. Jobs without a stable identity or older than 23 hours are refused before sending. This age limit must remain shorter than your server's key retention; it cannot exceed 23 hours.

**This is bounded retry protection, not a universal exactly-once guarantee.** The server must support and retain idempotency keys. Keep the same send order and content on retry. If custom jobs skip or reorder messages, explicitly identify each business send intent:

```php
use Illuminate\Mail\Mailables\Headers;

public function headers(): Headers
{
    return new Headers(text: [
        'X-Plunk-Idempotency-Key' => 'invoice:'.$this->invoice->id.':issued:v1',
    ]);
}
```

Keys must be unique per intended message and 1–255 bytes. Reuse the same key and content on retry. This internal header is removed from the delivered email; a hash of it and the recipient becomes the HTTP idempotency key. For synchronous/manual resends using explicit keys, the caller must stay within the server retention window. Old failed jobs require delivery review before redispatch. Avoid a failover mailer that sends through another provider after an ambiguous Plunk response: another provider cannot honor Plunk's key.

## Supported email content

HTML, plain-text input (escaped into HTML), display names, one Reply-To, custom headers, regular and inline attachments are supported. Plunk generates the delivered plain-text alternative from HTML; independently authored dual HTML/text bodies are not preserved. CC/BCC are rejected instead of silently converting them into visible To recipients. Use another transport if you require those semantics.

Provider acceptance is not final delivery. Delivery, bounce, complaint, open and click events remain Plunk's responsibility; configure its SES event webhooks and tracking settings separately.

## Direct API helpers

The inherited helpers remain available through `SohaibIlyas\PlunkLaravel\Facades\Plunk`:

```php
Plunk::sendEmail('reader@example.com', 'Welcome', '<p>Hello!</p>');
Plunk::sendTemplate('reader@example.com', 'template-id', ['name' => 'Reader']);
Plunk::verifyEmail('reader@example.com');
Plunk::trackEvent('reader@example.com', 'signed_up', ['plan' => 'pro']);
```

Event tracking requires `PLUNK_PUBLIC_KEY=pk_...`. Sending and verification use the secret key. **Direct API helpers do not have the Laravel transport's automatic deduplication.** Use Laravel Mail for retry-protected sending. Tracking business events is separate from Plunk's automatic delivery/open/click tracking.

## Development and releases

```sh
composer install
composer test
composer format
```

Tests use fake HTTP responses and do not send real emails. CI tests Laravel 12/PHP 8.3 and Laravel 13/PHP 8.4. Run tests, commit changes, and publish a new semantic version tag to release through Packagist. Never overwrite a released tag.

## Credits and license

Forked from [vivgui/plunk-laravel](https://github.com/vivgui/plunk-laravel), based on [Marcelo EatWorld's original transport](https://github.com/marceloeatworld/plunk-laravel). Includes vivgui's Laravel 13 compatibility and public/secret API key split. Maintained by [Sohaib Ilyas](https://github.com/sohaibilyas). MIT licensed; see [LICENSE.md](LICENSE.md).
