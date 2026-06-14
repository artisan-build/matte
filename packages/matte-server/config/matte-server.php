<?php

declare(strict_types=1);

$tokens = array_filter(array_map('trim', explode(',', (string) env('MATTE_TOKENS', ''))));

return [
    'runtime_path' => env('MATTE_RUNTIME_PATH', storage_path('matte-runtime')),
    'bg_remover_tag' => env('MATTE_BG_REMOVER_TAG', 'v0.7.1'),
    'onnx_version' => env('MATTE_ONNX_VERSION', '1.19.2'),
    'model' => env('MATTE_MODEL_NAME'),
    'model_url' => env('MATTE_MODEL_URL'),
    'disk' => env('MATTE_DISK', 'local'),
    'queue' => env('MATTE_QUEUE_CONNECTION'),
    'timeout' => (int) env('MATTE_TIMEOUT', 120),
    'default_mode' => env('MATTE_DEFAULT_MODE', 'grabcut'),
    'route_prefix' => env('MATTE_ROUTE_PREFIX', ''),
    'tokens' => array_values(array_filter(array_map(function (string $token): ?array {
        [$id, $hash] = array_pad(explode('=', $token, 2), 2, '');
        $id = trim($id);
        $hash = trim($hash);

        if ($id === '' || strlen($hash) !== 64 || ! ctype_xdigit($hash)) {
            return null;
        }

        return ['id' => $id, 'token_hash' => strtolower($hash)];
    }, $tokens))),
];
