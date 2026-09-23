<?php

declare(strict_types=1);

use ArtisanBuild\MatteServer\BinaryLocator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('streams the model download directly to its destination', function (): void {
    $runtimePath = sys_get_temp_dir().'/matte-provision-'.bin2hex(random_bytes(8));
    config()->set('matte-server.runtime_path', $runtimePath);
    config()->set('matte-server.model_url', 'https://downloads.example.test/model.onnx');
    config()->set('matte-server.model', 'model.onnx');

    $locator = BinaryLocator::fromSystem();
    mkdir($locator->libDir(), 0755, true);
    file_put_contents($locator->binaryPath(), 'binary');
    chmod($locator->binaryPath(), 0755);

    $onnx = $locator->onnxAsset();
    file_put_contents($locator->libDir().'/'.$onnx['lib'], 'library');

    $downloadOptions = null;

    Http::preventStrayRequests();
    Http::fake(function ($request, array $options) use (&$downloadOptions) {
        $downloadOptions = $options;

        return Http::response('model-contents');
    });

    try {
        $this->artisan('matte:provision-binary')
            ->assertSuccessful();

        $modelPath = $locator->modelPath('model.onnx');
        $sink = $downloadOptions['sink'] ?? null;

        expect($sink)->toBeString()
            ->and(dirname($sink))->toBe(dirname($modelPath))
            ->and(file_get_contents($modelPath))->toBe('model-contents');
    } finally {
        removeDirectory($runtimePath);
    }
});

it('verifies the checksum after streaming the binary download', function (): void {
    $runtimePath = sys_get_temp_dir().'/matte-provision-'.bin2hex(random_bytes(8));
    config()->set('matte-server.runtime_path', $runtimePath);
    config()->set('matte-server.model_url', null);

    $locator = BinaryLocator::fromSystem();
    mkdir($locator->libDir(), 0755, true);

    $onnx = $locator->onnxAsset();
    file_put_contents($locator->libDir().'/'.$onnx['lib'], 'library');

    $asset = $locator->binaryName();
    $downloadOptions = null;

    Http::preventStrayRequests();
    Http::fake(function ($request, array $options) use ($asset, &$downloadOptions) {
        if (str_ends_with($request->url(), '/checksums.txt')) {
            return Http::response(str_repeat('0', 64)."  {$asset}\n");
        }

        $downloadOptions = $options;

        return Http::response('binary-contents');
    });

    try {
        expect(fn () => Artisan::call('matte:provision-binary'))
            ->toThrow(RuntimeException::class, "Checksum mismatch for {$asset}.");

        $sink = $downloadOptions['sink'] ?? null;

        expect($sink)->toBeString()
            ->and(dirname($sink))->toBe(dirname($locator->binaryPath()))
            ->and(is_file($locator->binaryPath()))->toBeFalse()
            ->and(is_file($locator->binaryPath().'.download'))->toBeFalse();
    } finally {
        removeDirectory($runtimePath);
    }
});
