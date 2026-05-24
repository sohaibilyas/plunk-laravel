<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plunk Secret API Key (sk_*)
    |--------------------------------------------------------------------------
    |
    | The secret key from your Plunk dashboard. Used for server-side endpoints:
    | /v1/send, /v1/verify, and any other sk_*-protected endpoint. Never expose
    | this key to browsers or mobile clients.
    |
    */
    'api_key' => env('PLUNK_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Plunk Public API Key (pk_*)
    |--------------------------------------------------------------------------
    |
    | The public key from your Plunk dashboard. Required for the /v1/track
    | endpoint, which only accepts pk_* keys. Calling trackEvent() without
    | this set will throw a RuntimeException. Leave unset if you do not use
    | event tracking — sendEmail/sendTemplate/verifyEmail are unaffected.
    |
    */
    'public_api_key' => env('PLUNK_PUBLIC_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Plunk API URL
    |--------------------------------------------------------------------------
    |
    | This is the base URL for the Plunk API. For the official Plunk service,
    | this should be 'https://api.useplunk.com'. For self-hosted instances,
    | it will be your domain, for example 'https://plunk.yourdomain.com'.
    |
    */
    'api_url' => env('PLUNK_API_URL', 'https://api.useplunk.com'),

    /*
    |--------------------------------------------------------------------------
    | Plunk API Endpoint
    |--------------------------------------------------------------------------
    |
    | This is the endpoint for sending emails. For the official Plunk service,
    | this should be '/v1/send'. For self-hosted instances, it might be
    | different, for example '/api/v1/send'.
    |
    */
    'endpoint' => env('PLUNK_API_ENDPOINT', '/v1/send'),
];