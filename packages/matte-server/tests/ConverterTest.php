<?php

declare(strict_types=1);

use ArtisanBuild\MatteContracts\EdgeMode;
use ArtisanBuild\MatteContracts\Mode;
use ArtisanBuild\MatteContracts\Preset;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\BinaryLocator;
use ArtisanBuild\MatteServer\Converter;
use ArtisanBuild\MatteServer\Exceptions\ConversionFailed;

it('builds the grabcut command without a model', function (): void {
    $runtimePath = sys_get_temp_dir().'/matte-converter-test-'.bin2hex(random_bytes(6));
    config()->set('matte-server.runtime_path', $runtimePath);

    $converter = new Converter(new BinaryLocator(PHP_OS_FAMILY, php_uname('m')));

    expect($converter->command('/tmp/input.png', '/tmp/output.png', new RemovalOptions(
        mode: Mode::Grabcut,
        preset: Preset::Balanced,
        edgeMode: EdgeMode::Blur,
        iterations: 4,
        margin: 6,
    )))->toBe([
        $runtimePath.'/bin/'.(new BinaryLocator(PHP_OS_FAMILY, php_uname('m')))->binaryName(),
        '-i',
        '/tmp/input.png',
        '-o',
        '/tmp/output.png',
        '-q',
        'balanced',
        '--grabcut',
        '--edge-mode',
        'blur',
        '-n',
        '4',
        '-m',
        '6',
    ]);
});

it('builds the ml command with a model and without grabcut', function (): void {
    $runtimePath = sys_get_temp_dir().'/matte-converter-test-'.bin2hex(random_bytes(6));
    $modelPath = $runtimePath.'/models/model.onnx';

    mkdir(dirname($modelPath), 0777, true);
    touch($modelPath);
    config()->set('matte-server.runtime_path', $runtimePath);
    config()->set('matte-server.model', 'model.onnx');

    try {
        $locator = new BinaryLocator(PHP_OS_FAMILY, php_uname('m'));
        $converter = new Converter($locator);

        expect($converter->command('/tmp/input.png', '/tmp/output.png', new RemovalOptions(
            mode: Mode::Ml,
            preset: Preset::Quality,
        )))->toBe([
            $locator->binaryPath(),
            '-i',
            '/tmp/input.png',
            '-o',
            '/tmp/output.png',
            '-q',
            'quality',
            '--model',
            $modelPath,
        ]);
    } finally {
        removeDirectory($runtimePath);
    }
});

it('fails ml conversion when the model file is missing', function (): void {
    $runtimePath = sys_get_temp_dir().'/matte-converter-test-'.bin2hex(random_bytes(6));
    config()->set('matte-server.runtime_path', $runtimePath);
    config()->set('matte-server.model', 'missing.onnx');

    $converter = new Converter(new BinaryLocator(PHP_OS_FAMILY, php_uname('m')));

    expect(fn () => $converter->command('/tmp/input.png', '/tmp/output.png', new RemovalOptions(
        mode: Mode::Ml,
        preset: Preset::Balanced,
    )))->toThrow(ConversionFailed::class);
});
