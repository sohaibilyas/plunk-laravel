<?php

return [
    'secret_key' => env('PLUNK_SECRET_KEY', env('PLUNK_API_KEY')),
    'public_key' => env('PLUNK_PUBLIC_KEY', env('PLUNK_PUBLIC_API_KEY')),
    'base_api_url' => env('PLUNK_BASE_API_URL', env('PLUNK_API_URL', 'https://api.useplunk.com')),
    'endpoint' => env('PLUNK_API_ENDPOINT', '/v1/send'),
    'timeout' => (int) env('PLUNK_TIMEOUT', 15),
    'connect_timeout' => (int) env('PLUNK_CONNECT_TIMEOUT', 5),

    // Must be shorter than the server's idempotency retention (24 hours by default).
    'mail_max_job_age' => (int) env('PLUNK_MAIL_MAX_JOB_AGE', 23 * 60 * 60),
];
