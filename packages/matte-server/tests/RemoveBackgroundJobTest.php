<?php

declare(strict_types=1);

use ArtisanBuild\MatteContracts\Mode;
use ArtisanBuild\MatteContracts\Preset;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\Converter;
use ArtisanBuild\MatteServer\Jobs\RemoveBackgroundJob;
use ArtisanBuild\MatteServer\MatteJob;
use ArtisanBuild\MatteServer\OutputKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('processes a queued removal when host dependencies are available', function (): void {
    $this->artisan('migrate')->assertExitCode(0);

    $runtimePath = sys_get_temp_dir().'/matte-runtime-test-'.bin2hex(random_bytes(6));
    config()->set('matte-server.runtime_path', $runtimePath);
    config()->set('matte-server.onnx_version', '1.19.2');

    Storage::fake('matte-test');

    try {
        expect(provisionBinaryForRemoveBackgroundJob())->toBe(0);

        $converter = app(Converter::class);

        if (! $converter->dependenciesAvailable()) {
            $this->markTestSkipped('Matte binary dependencies are unavailable; macOS hosts need `brew install opencv onnxruntime`.');
        }

        $inputRef = 'inputs/doctor-sample.png';
        $bytes = file_get_contents(__DIR__.'/../resources/doctor-sample.png');
        expect($bytes)->toBeString();
        Storage::disk('matte-test')->put($inputRef, $bytes);

        $options = new RemovalOptions(mode: Mode::Grabcut, preset: Preset::Fast);
        $outputKey = OutputKey::for($bytes, $options);
        $matteJob = MatteJob::factory()->create([
            'input_ref' => $inputRef,
            'mode' => $options->mode->value,
            'preset' => $options->preset->value,
        ]);

        (new RemoveBackgroundJob($matteJob->id, $options, 'matte-test', $inputRef, $outputKey))->handle($converter);

        Storage::disk('matte-test')->assertExists($outputKey);
        expect(isPngWithAlpha(Storage::disk('matte-test')->path($outputKey)))->toBeTrue()
            ->and($matteJob->refresh()->status->value)->toBe('done')
            ->and($matteJob->output_ref)->toBe($outputKey);
    } finally {
        removeDirectory($runtimePath);
    }
});

function provisionBinaryForRemoveBackgroundJob(): int
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
