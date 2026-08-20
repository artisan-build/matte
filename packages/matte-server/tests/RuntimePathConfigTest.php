<?php

declare(strict_types=1);

afterEach(function (): void {
    putenv('MATTE_RUNTIME_PATH');
    unset($_ENV['MATTE_RUNTIME_PATH'], $_SERVER['MATTE_RUNTIME_PATH']);
});

it('defaults the runtime path to a base_path location so it ships in the build artifact', function (): void {
    expect(config('matte-server.runtime_path'))->toBe(base_path('runtime'));
});

it('honors the MATTE_RUNTIME_PATH override when it is set', function (): void {
    putenv('MATTE_RUNTIME_PATH=/custom/matte-runtime');
    $_ENV['MATTE_RUNTIME_PATH'] = '/custom/matte-runtime';
    $_SERVER['MATTE_RUNTIME_PATH'] = '/custom/matte-runtime';

    $config = require __DIR__.'/../config/matte-server.php';

    expect($config['runtime_path'])->toBe('/custom/matte-runtime');
});
