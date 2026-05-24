# Upgrading from v1.x to v2.0

v2.0 splits Plunk's secret and public API keys so that each endpoint is called with the correct credential. Plunk's `/v1/track` endpoint only accepts public keys (`pk_*`); v1 sent the secret key to every endpoint and got 401s back from tracking.

## Breaking changes

### `PlunkService` constructor now takes two keys

If you instantiate `PlunkService` manually:

```php
// v1
new PlunkService($secretKey, $baseUrl, $endpoint);

// v2
new PlunkService($secretKey, $publicKey, $baseUrl, $endpoint);
```

`$publicKey` is `?string` — pass `null` if you don't use event tracking.

If you use the Laravel service container (the standard path), set the new env var and you're done:

```env
PLUNK_PUBLIC_API_KEY=pk_xxxxxxxxxxxx
```

### `trackEvent()` now requires a public key

`/v1/track` only accepts `pk_*` keys. Calling `trackEvent()` without `PLUNK_PUBLIC_API_KEY` set throws a `RuntimeException` with a clear message. Set the env var to fix.

If you don't use `trackEvent()`, nothing changes — `sendEmail()`, `sendTemplate()`, and `verifyEmail()` continue to use the secret key, and the `mail.default=plunk` transport is unaffected.

## Endpoint → key map

| Method            | Endpoint     | Key   |
|-------------------|--------------|-------|
| `sendEmail()`     | `/v1/send`   | `sk_*` |
| `sendTemplate()`  | `/v1/send`   | `sk_*` |
| `trackEvent()`    | `/v1/track`  | `pk_*` |
| `verifyEmail()`   | `/v1/verify` | `sk_*` |
