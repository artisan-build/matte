<?php

declare(strict_types=1);

return [
    'runtime_path' => env('MATTE_RUNTIME_PATH', storage_path('matte-runtime')),
    'bg_remover_tag' => env('MATTE_BG_REMOVER_TAG', 'v0.8.0'),
    'onnx_version' => env('MATTE_ONNX_VERSION', '1.19.2'),
    'model' => env('MATTE_MODEL_NAME', 'isnet-general-use.onnx'),
    'model_url' => env('MATTE_MODEL_URL', 'https://github.com/artisan-build/bg-remover/releases/download/'.env('MATTE_BG_REMOVER_TAG', 'v0.8.0').'/isnet-general-use.onnx'),
    'disk' => env('MATTE_DISK', env('FILESYSTEM_DISK', 'local')),
    'queue' => env('MATTE_QUEUE_CONNECTION'),
    'webhook_secret' => env('MATTE_WEBHOOK_SECRET'),
    'timeout' => (int) env('MATTE_TIMEOUT', 120),
    'default_mode' => env('MATTE_DEFAULT_MODE', 'ml'),
    'route_prefix' => env('MATTE_ROUTE_PREFIX', ''),
];
