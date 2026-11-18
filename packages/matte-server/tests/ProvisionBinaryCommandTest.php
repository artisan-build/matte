<?php

declare(strict_types=1);

use ArtisanBuild\MatteServer\BinaryLocator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

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

it('provisions ONNX from the configured base URL only after verifying its streamed archive', function (bool $validChecksum): void {
    $runtimePath = sys_get_temp_dir().'/matte-provision-'.bin2hex(random_bytes(8));
    config()->set('matte-server.runtime_path', $runtimePath);
    config()->set('matte-server.model_url', null);
    config()->set('matte-server.onnx_base_url', 'https://mirror.example.test/onnx');

    $locator = BinaryLocator::fromSystem();
    mkdir($locator->libDir(), 0755, true);
    file_put_contents($locator->binaryPath(), 'binary');
    chmod($locator->binaryPath(), 0755);

    $onnx = $locator->onnxAsset();
    $baseUrl = 'https://mirror.example.test/onnx/v'.config('matte-server.onnx_version');
    $archivePath = $runtimePath.'/'.$onnx['tgz'];
    $archive = 'onnx-archive-contents';
    $hash = $validChecksum ? hash('sha256', $archive) : str_repeat('0', 64);
    $downloadOptions = null;

    Http::preventStrayRequests();
    Http::fake(function ($request, array $options) use ($baseUrl, $onnx, $hash, $archive, &$downloadOptions) {
        if ($request->url() === "{$baseUrl}/checksums.txt") {
            return Http::response($hash.'  '.$onnx['tgz']."\n");
        }

        if ($request->url() === $baseUrl.'/'.$onnx['tgz']) {
            $downloadOptions = $options;

            return Http::response($archive);
        }

        throw new RuntimeException('Unexpected URL: '.$request->url());
    });

    Process::fake(function () use ($runtimePath, $onnx, $archivePath, $archive) {
        expect(file_get_contents($archivePath))->toBe($archive);
        file_put_contents($runtimePath.'/onnxruntime-extract/'.$onnx['lib'], 'library');

        return Process::result();
    });

    try {
        if ($validChecksum) {
            expect(Artisan::call('matte:provision-binary'))->toBe(0)
                ->and(file_get_contents($locator->libDir().'/'.$onnx['lib']))->toBe('library');
        } else {
            expect(fn () => Artisan::call('matte:provision-binary'))
                ->toThrow(RuntimeException::class, 'Checksum mismatch for '.$onnx['tgz'].'.');

            Process::assertNothingRan();
            expect(is_file($locator->libDir().'/'.$onnx['lib']))->toBeFalse();
        }

        Http::assertSent(fn ($request) => $request->url() === "{$baseUrl}/checksums.txt");
        Http::assertSent(fn ($request) => $request->url() === $baseUrl.'/'.$onnx['tgz']);
        expect($downloadOptions['sink'] ?? null)->toBe($archivePath.'.download')
            ->and(is_file($archivePath))->toBeFalse()
            ->and(is_file($archivePath.'.download'))->toBeFalse();
    } finally {
        removeDirectory($runtimePath);
    }
})->with(['matching checksum' => true, 'mismatched checksum' => false]);

it('defaults ONNX downloads to the first-party mirror', function (): void {
    expect(config('matte-server.onnx_base_url'))
        ->toBe('https://github.com/artisan-build/onnxruntime-mirror/releases/download');
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
