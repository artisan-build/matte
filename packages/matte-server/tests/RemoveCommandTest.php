<?php

declare(strict_types=1);

use ArtisanBuild\MatteServer\Converter;
use Illuminate\Support\Facades\Artisan;

it('removes an image synchronously when host dependencies are available', function (): void {
    $runtimePath = sys_get_temp_dir().'/matte-runtime-test-'.bin2hex(random_bytes(6));
    $outputPath = sys_get_temp_dir().'/matte-remove-test-'.bin2hex(random_bytes(6)).'.png';
    config()->set('matte-server.runtime_path', $runtimePath);
    config()->set('matte-server.onnx_version', '1.19.2');

    try {
        expect(provisionBinaryForRemoveCommand())->toBe(0);

        $converter = app(Converter::class);
        $inputPath = __DIR__.'/../resources/doctor-sample.png';

        if (! $converter->dependenciesAvailable()) {
            $exitCode = Artisan::call('matte:remove', [
                'input' => $inputPath,
                '--grabcut' => true,
                '--out' => $outputPath,
            ]);

            expect($exitCode)->not->toBe(0)
                ->and(Artisan::output())->toContain('brew install opencv onnxruntime');

            return;
        }

        $exitCode = Artisan::call('matte:remove', [
            'input' => $inputPath,
            '--grabcut' => true,
            '--out' => $outputPath,
        ]);

        expect($exitCode)->toBe(0)
            ->and(isPngWithAlpha($outputPath))->toBeTrue()
            ->and(Artisan::output())->toContain($outputPath);
    } finally {
        @unlink($outputPath);
        removeDirectory($runtimePath);
    }
});

function provisionBinaryForRemoveCommand(): int
{
    $provisionExitCode = null;

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $provisionExitCode = Artisan::call('matte:provision-binary');

        if ($provisionExitCode === 0) {
            break;
        }

        usleep($attempt * 250_000);
    }

    return (int) $provisionExitCode;
}
