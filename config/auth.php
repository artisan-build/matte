<?php

return [
    'defaults' => [
        'guard' => null,
        'passwords' => null,
    ],

    'guards' => [],

    'providers' => [],

    'passwords' => [],
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
