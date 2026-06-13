<?php

declare(strict_types=1);

return [
    'runtime_path' => storage_path('matte-runtime'),
    'bg_remover_tag' => env('MATTE_BG_REMOVER_TAG', 'v0.7.1'),
    'onnx_version' => env('MATTE_ONNX_VERSION', '1.19.2'),
    'model' => env('MATTE_MODEL_NAME'),
    'model_url' => env('MATTE_MODEL_URL'),
];
