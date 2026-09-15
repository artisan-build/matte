<?php

declare(strict_types=1);

return [
    'url' => env('MATTE_URL'),
    'token' => env('MATTE_TOKEN'),
    // Dormant v0.13.0 residue: the server rejects callback submissions in this release.
    'webhook_secret' => env('MATTE_WEBHOOK_SECRET'),
    'webhook_path' => env('MATTE_WEBHOOK_PATH'),
    'store_disk' => env('MATTE_STORE_DISK'),
    'default_mode' => env('MATTE_DEFAULT_MODE', 'ml'),
    'default_preset' => env('MATTE_DEFAULT_PRESET', 'balanced'),
    'poll_interval' => (int) env('MATTE_POLL_INTERVAL', 2),
    'poll_timeout' => (int) env('MATTE_POLL_TIMEOUT', 120),
];
