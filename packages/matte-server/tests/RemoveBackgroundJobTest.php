<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Actions\ActivateCredential;
use ArtisanBuild\BuiltForCloud\Actions\MintCredential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Hmac\HmacEnvelope;
use ArtisanBuild\BuiltForCloud\Hmac\HmacVerifier;
use ArtisanBuild\BuiltForCloud\MintOptions;
use ArtisanBuild\MatteContracts\JobStatusEnvelope;
use ArtisanBuild\MatteContracts\Mode;
use ArtisanBuild\MatteContracts\Preset;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\BinaryLocator;
use ArtisanBuild\MatteServer\CallbackDestination;
use ArtisanBuild\MatteServer\Converter;
use ArtisanBuild\MatteServer\Jobs\RemoveBackgroundJob;
use ArtisanBuild\MatteServer\MatteJob;
use ArtisanBuild\MatteServer\OutputKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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

it('sends the exact terminal body to a registered destination with a package-bound signature', function (): void {
    $this->artisan('migrate')->assertExitCode(0);
    config()->set('matte-server.callback.destinations.consumer-app', [
        'url' => 'https://consumer.example/matte/callback',
        'subject_ref' => 'consumer-routing-identity',
        'installation' => 'consumer-installation',
        'application' => 'consumer-application',
        'audience' => 'consumer.example',
    ]);
    $destination = CallbackDestination::resolve('consumer-app');
    expect($destination)->not->toBeNull();
    $mint = app(MintCredential::class)(
        $destination->scope->subject,
        new MintOptions(
            kind: CredentialKind::Hmac,
            purpose: CredentialPurpose::Signing,
            codeTtlSeconds: 3600,
            boundScope: $destination->scope,
        ),
    );
    $delivery = $this->postJson('/bfc/onboarding/exchange', [
        'token' => $mint->secret?->reveal(),
        'version' => 1,
    ])->assertCreated();
    app(ActivateCredential::class)(
        (string) $delivery->json('credential_id'),
        (string) $delivery->json('delivery_fingerprint'),
    );
    $sentRequest = null;
    $sentOptions = null;
    Http::fake(function ($request, array $options) use (&$sentRequest, &$sentOptions) {
        $sentRequest = $request;
        $sentOptions = $options;

        return Http::response(status: 204);
    });
    Process::fake([
        '*' => Process::result(exitCode: 1),
    ]);
    Storage::fake('matte-test');

    $inputRef = 'inputs/input.png';
    Storage::disk('matte-test')->put($inputRef, 'image-bytes');
    $matteJob = MatteJob::factory()->create([
        'input_ref' => $inputRef,
    ]);
    $job = new RemoveBackgroundJob(
        $matteJob->id,
        new RemovalOptions(mode: Mode::Grabcut, preset: Preset::Fast),
        'matte-test',
        $inputRef,
        'outputs/result.png',
        'consumer-app',
    );

    $job->handle(new Converter(new BinaryLocator(PHP_OS_FAMILY, php_uname('m'))));

    Http::assertSentCount(1);
    expect($sentRequest)->not->toBeNull()
        ->and($sentRequest->url())->toBe('https://consumer.example/matte/callback')
        ->and($sentOptions['timeout'] ?? null)->toBe(5)
        ->and($sentOptions['connect_timeout'] ?? null)->toBe(2);
    $header = $sentRequest->header(HmacEnvelope::HEADER);
    expect($header)->toHaveCount(1);
    app(HmacVerifier::class)->verifyBound($destination->scope, $header[0], $sentRequest->body());
    $payload = JobStatusEnvelope::fromJson($sentRequest->body());
    expect($matteJob->refresh()->status->value)->toBe('failed')
        ->and($payload->jobId)->toBe($matteJob->id)
        ->and($payload->status->value)->toBe('failed');
});

it('keeps the durable terminal state when callback signing is unavailable', function (): void {
    $this->artisan('migrate')->assertExitCode(0);
    config()->set('matte-server.callback.destinations.consumer-app', [
        'url' => 'https://consumer.example/matte/callback',
        'subject_ref' => 'consumer-routing-identity',
        'installation' => 'consumer-installation',
        'application' => 'consumer-application',
        'audience' => 'consumer.example',
    ]);
    Http::fake();
    Process::fake(['*' => Process::result(exitCode: 1)]);
    Storage::fake('matte-test');
    Storage::disk('matte-test')->put('inputs/input.png', 'image-bytes');
    $matteJob = MatteJob::factory()->create(['input_ref' => 'inputs/input.png']);

    (new RemoveBackgroundJob(
        $matteJob->id,
        new RemovalOptions(mode: Mode::Grabcut, preset: Preset::Fast),
        'matte-test',
        'inputs/input.png',
        'outputs/result.png',
        'consumer-app',
    ))->handle(new Converter(new BinaryLocator(PHP_OS_FAMILY, php_uname('m'))));

    expect($matteJob->refresh()->status->value)->toBe('failed');
    Http::assertNothingSent();
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
