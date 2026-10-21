# Upgrading to sohaibilyas/plunk-laravel 3

This is an independently maintained fork with a new Composer name and PHP namespace.

1. Remove `marceloeatworld/plunk-laravel` or `nextmigrant/laravel-plunk` before installing this package. Both conflict because they register overlapping configuration/providers.
2. Require `sohaibilyas/plunk-laravel:^3.0` and use `SohaibIlyas\PlunkLaravel` instead of `MarceloEatWorld\PlunkLaravel` imports.
3. Remove application-level registrations of the `plunk` transport and duplicate queue lifecycle hooks. Package discovery now supplies them.
4. Review your existing published `config/plunk.php`. The canonical keys are `secret_key`, `public_key`, `base_api_url`, `endpoint`, `timeout`, `connect_timeout`, and `mail_max_job_age`. The package configuration accepts legacy `PLUNK_API_KEY`, `PLUNK_PUBLIC_API_KEY` and `PLUNK_API_URL` environment variables as fallbacks.
5. CC/BCC now throw instead of being merged into To. API errors and malformed acceptance responses throw from the transport. Direct helper methods still return result arrays.
6. The transport requires HTTPS and server-side idempotency with at least 24-hour retention. Read the retry limits and explicit-key guidance in README before enabling queued sending.
7. Rebuild configuration and restart application/queue workers. Do not switch transport implementations while a failed send is awaiting an ambiguous retry; review those sends first because the request-key namespace changes.

The supported framework range is Laravel 12–13 on PHP 8.3+. No dependency on the NextMigrant SDK remains.

## Version 3.0.1

The package now registers `mail.mailers.plunk` automatically. You may remove a manual `['transport' => 'plunk']` entry from `config/mail.php`. Keep any application-specific overrides you need; they take precedence over the package default. Continue selecting the mailer with `MAIL_MAILER=plunk`.
