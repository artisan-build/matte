<?php

declare(strict_types=1);

use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteContracts\Mode;
use ArtisanBuild\MatteContracts\Preset;
use ArtisanBuild\MatteContracts\Protocol;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\Converter;
use ArtisanBuild\MatteServer\Jobs\RemoveBackgroundJob;
use ArtisanBuild\MatteServer\MatteJob;
use ArtisanBuild\MatteServer\OutputKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->artisan('migrate')->assertExitCode(0);
    config()->set('matte-server.disk', 'matte-test');
    config()->set('matte-server.tokens', [
        ['id' => 'app', 'token_hash' => hash('sha256', 'known-token')],
    ]);
});

it('rejects remove requests without a bearer token', function (): void {
    $this->postJson('/v1/remove')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthorized.']);
});

it('queues remove requests with valid bearer tokens', function (): void {
    Queue::fake();
    Storage::fake('matte-test');

    $image = UploadedFile::fake()->image('x.png');
    $bytes = file_get_contents($image->getRealPath());
    expect($bytes)->toBeString();

    $response = $this->withToken('known-token')->postJson('/v1/remove', [
        'image' => $image,
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('envelope_version', Protocol::ENVELOPE_VERSION)
        ->assertJsonPath('status', 'queued')
        ->assertJsonStructure(['job_id']);

    $jobId = $response->json('job_id');
    $outputKey = OutputKey::for($bytes, new RemovalOptions(mode: Mode::Grabcut, preset: Preset::Balanced));

    expect(MatteJob::query()->find($jobId))
        ->not->toBeNull()
        ->status->toBe(JobStatus::Queued);

    Queue::assertPushed(RemoveBackgroundJob::class, function (RemoveBackgroundJob $job) use ($jobId, $outputKey): bool {
        return $job->matteJobId === $jobId
            && $job->diskName === 'matte-test'
            && $job->outputKey === $outputKey;
    });
});

it('rejects future envelope versions', function (): void {
    Storage::fake('matte-test');

    $this->withToken('known-token')->postJson('/v1/remove', [
        'envelope_version' => Protocol::ENVELOPE_VERSION + 1,
        'image' => UploadedFile::fake()->image('x.png'),
    ])->assertUnprocessable();
});

it('rejects invalid removal options', function (): void {
    Storage::fake('matte-test');

    $this->withToken('known-token')->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('x.png'),
        'mode' => 'banana',
    ])->assertUnprocessable();
});

it('shows existing jobs as job status envelopes', function (): void {
    $matteJob = MatteJob::factory()->done()->create();

    $this->getJson('/v1/jobs/'.$matteJob->id)
        ->assertSuccessful()
        ->assertJson([
            'envelope_version' => Protocol::ENVELOPE_VERSION,
            'job_id' => $matteJob->id,
            'status' => 'done',
            'output_ref' => $matteJob->output_ref,
        ]);
});

it('returns not found for unknown jobs', function (): void {
    $this->getJson('/v1/jobs/missing')->assertNotFound();
});

it('rejects result requests without a bearer token', function (): void {
    $this->getJson('/v1/jobs/missing/result')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthorized.']);
});

it('returns not found for unknown job results', function (): void {
    $this->withToken('known-token')->getJson('/v1/jobs/missing/result')
        ->assertNotFound()
        ->assertJson(['message' => 'Job not found.']);
});

it('rejects incomplete job results', function (): void {
    $matteJob = MatteJob::factory()->create();

    $this->withToken('known-token')->getJson('/v1/jobs/'.$matteJob->id.'/result')
        ->assertStatus(409)
        ->assertJson([
            'message' => 'Job is not complete.',
            'status' => 'queued',
        ]);
});

it('returns completed job result bytes', function (): void {
    Storage::fake('matte-test');

    $bytes = 'png-bytes';
    $outputRef = 'outputs/result.png';
    Storage::disk('matte-test')->put($outputRef, $bytes);
    $matteJob = MatteJob::factory()->done()->create(['output_ref' => $outputRef]);

    $response = $this->withToken('known-token')->get('/v1/jobs/'.$matteJob->id.'/result');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'image/png');

    expect($response->getContent())->toBe($bytes);
});

it('returns not found when completed job result object is missing', function (): void {
    Storage::fake('matte-test');

    $matteJob = MatteJob::factory()->done()->create(['output_ref' => 'outputs/missing.png']);

    $this->withToken('known-token')->getJson('/v1/jobs/'.$matteJob->id.'/result')
        ->assertNotFound()
        ->assertJson(['message' => 'Result not available.']);
});

it('runs sync conversion when dependencies are available and reports remediation otherwise', function (): void {
    Storage::fake('matte-test');

    $runtimePath = sys_get_temp_dir().'/matte-runtime-test-'.bin2hex(random_bytes(6));
    config()->set('matte-server.runtime_path', $runtimePath);
    config()->set('matte-server.onnx_version', '1.19.2');

    try {
        $provisionExitCode = provisionBinaryForRemoveHttp();
        $converter = app(Converter::class);

        if (PHP_OS_FAMILY !== 'Darwin') {
            expect($provisionExitCode)->toBe(0);
        }

        $response = $this->withToken('known-token')->post('/v1/remove?sync=1', [
            'image' => new UploadedFile(__DIR__.'/../resources/doctor-sample.png', 'doctor-sample.png', 'image/png', null, true),
            'mode' => 'grabcut',
            'preset' => 'fast',
        ], ['Accept' => 'application/json']);

        if (! $converter->dependenciesAvailable()) {
            $response->assertStatus(503)
                ->assertJsonPath('remediation', 'macOS: run `brew install opencv onnxruntime`');

            return;
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'matte-http-response-');
        expect($outputPath)->toBeString();

        try {
            file_put_contents($outputPath, $response->getContent());

            $response->assertSuccessful()
                ->assertHeader('Content-Type', 'image/png');

            expect(isPngWithAlpha($outputPath))->toBeTrue();
        } finally {
            @unlink($outputPath);
        }
    } finally {
        removeDirectory($runtimePath);
    }
});

function provisionBinaryForRemoveHttp(): int
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
