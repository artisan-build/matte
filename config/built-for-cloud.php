<?php

declare(strict_types=1);

return [
    'manifest' => [
        'name' => 'Matte',
        'slug' => 'matte',
        'description' => 'Background removal as an API you own: submit an image, poll the job, fetch a transparent PNG.',
        'icon' => 'https://scalpels.app/products/matte/icon.svg',
        'product_url' => 'https://scalpels.app/products/matte',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [
            'matte.remove' => 'consumption',
        ],
    ],

    'ui' => [
        'landing_page' => false,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => ['matte.remove'],
    ],
];
