<?php

declare(strict_types=1);

return [
    'url' => env('MATTE_URL'),
    'token' => env('MATTE_TOKEN'),
    'callback' => [
        'path' => env('MATTE_CALLBACK_PATH', 'matte/callback'),
        'subject_ref' => env('MATTE_CALLBACK_SUBJECT_REF'),
        'installation' => env('MATTE_CALLBACK_INSTALLATION'),
        'application' => env('MATTE_CALLBACK_APPLICATION'),
        'audience' => env('MATTE_CALLBACK_AUDIENCE'),
    ],
    'store_disk' => env('MATTE_STORE_DISK'),
    'default_mode' => env('MATTE_DEFAULT_MODE', 'ml'),
    'default_preset' => env('MATTE_DEFAULT_PRESET', 'balanced'),
    'poll_interval' => (int) env('MATTE_POLL_INTERVAL', 2),
    'poll_timeout' => (int) env('MATTE_POLL_TIMEOUT', 120),
];
