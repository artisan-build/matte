<?php

declare(strict_types=1);

return [
    'manifest' => [
        'name' => 'Matte',
        'slug' => 'matte',
        'description' => 'Background removal as an API you own: submit an image, poll the job, fetch a transparent PNG.',
        'icon' => 'https://scalpels.app/img/products/transparent/matte.png',
        'product_url' => 'https://scalpels.app/products/matte',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [
            'matte.remove' => 'consumption',
            'matte.callback' => 'signing',
        ],
    ],

    'ui' => [
        'landing_page' => false,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => ['matte.remove', 'matte.callback'],
    ],

    'dashboard_prompts' => [
        [
            'title' => 'How is Matte doing?',
            'ask' => 'List the most recent background-removal jobs, how many succeeded or failed, and how long they took.',
        ],
        [
            'title' => 'Why did a job fail?',
            'ask' => 'Find the most recent failed job, look up its status, and explain what went wrong and what to try next.',
        ],
        [
            'title' => 'Is anything stuck?',
            'ask' => 'Look for jobs that have been queued or processing for longer than a few minutes and tell me which ones.',
        ],
    ],
];
