<?php

declare(strict_types=1);

use ArtisanBuild\MatteContracts\EdgeMode;
use ArtisanBuild\MatteContracts\Exceptions\InvalidEnvelope;
use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteContracts\JobStatusEnvelope;
use ArtisanBuild\MatteContracts\Mode;
use ArtisanBuild\MatteContracts\Preset;
use ArtisanBuild\MatteContracts\Protocol;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteContracts\RemovalRequest;

it('round-trips removal requests through arrays and json', function (): void {
    $request = RemovalRequest::make(
        new RemovalOptions(
            mode: Mode::Grabcut,
            preset: Preset::Quality,
            model: 'u2net',
            edgeMode: EdgeMode::Guided,
            iterations: 3,
            margin: 8,
        ),
        idempotencyKey: 'idem-123',
        callbackUrl: 'https://example.com/callback',
    );

    $fromArray = RemovalRequest::fromArray($request->toArray());
    $fromJson = RemovalRequest::fromJson($request->toJson());

    expect($fromArray->toArray())->toBe($request->toArray())
        ->and($fromJson->toArray())->toBe($request->toArray())
        ->and($fromArray->options->mode)->toBe(Mode::Grabcut)
        ->and($fromArray->options->preset)->toBe(Preset::Quality)
        ->and($fromArray->options->edgeMode)->toBe(EdgeMode::Guided);
});

it('supplies removal request defaults', function (): void {
    $request = RemovalRequest::make(new RemovalOptions);

    expect($request->options->mode)->toBe(Mode::Ml)
        ->and($request->options->preset)->toBe(Preset::Balanced)
        ->and($request->envelopeVersion)->toBe(1)
        ->and($request->toArray()['envelope_version'])->toBe(1);
});

it('ignores unknown newer fields', function (): void {
    $request = RemovalRequest::fromArray([
        'envelope_version' => 1,
        'options' => [
            'mode' => 'ml',
            'preset' => 'fast',
        ],
        'a_future_field' => 'x',
    ]);

    expect($request->options->preset)->toBe(Preset::Fast);
});

it('defaults absent optional fields from older payloads', function (): void {
    $request = RemovalRequest::fromArray([
        'envelope_version' => 1,
        'options' => [],
    ]);

    expect($request->options->mode)->toBe(Mode::Ml)
        ->and($request->options->preset)->toBe(Preset::Balanced)
        ->and($request->idempotencyKey)->toBeNull()
        ->and($request->callbackUrl)->toBeNull();
});

it('exposes protocol version helpers', function (): void {
    expect(Protocol::versionFrom(['envelope_version' => 7]))->toBe(7)
        ->and(Protocol::isSupported(7))->toBeFalse()
        ->and(Protocol::isSupported(1))->toBeTrue();
});

it('rejects malformed removal request envelopes', function (array $payload): void {
    RemovalRequest::fromArray($payload);
})->with([
    'invalid enum string' => [[
        'envelope_version' => 1,
        'options' => ['mode' => 'banana'],
    ]],
    'missing envelope version' => [[
        'options' => [],
    ]],
    'non-positive iterations' => [[
        'envelope_version' => 1,
        'options' => ['iterations' => 0],
    ]],
])->throws(InvalidEnvelope::class);

it('round-trips job status envelopes', function (): void {
    $envelope = JobStatusEnvelope::make(
        jobId: 'job-123',
        status: JobStatus::Done,
        outputRef: 's3://bucket/output.png',
        error: null,
    );

    $fromArray = JobStatusEnvelope::fromArray($envelope->toArray());
    $fromJson = JobStatusEnvelope::fromJson($envelope->toJson());

    expect($fromArray->toArray())->toBe($envelope->toArray())
        ->and($fromJson->toArray())->toBe($envelope->toArray())
        ->and($fromArray->status)->toBe(JobStatus::Done);
});

it('rejects unknown job statuses', function (): void {
    JobStatusEnvelope::fromArray([
        'envelope_version' => 1,
        'job_id' => 'job-123',
        'status' => 'mystery',
    ]);
})->throws(InvalidEnvelope::class);

it('stays framework-free', function (): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        expect($contents)->not->toContain('Illuminate\\')
            ->and($contents)->not->toContain('Laravel\\');
    }
});
