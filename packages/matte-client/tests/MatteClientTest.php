<?php

declare(strict_types=1);

use ArtisanBuild\MatteClient\Events\MatteRemovalCompleted;
use ArtisanBuild\MatteClient\Facades\Matte;
use ArtisanBuild\MatteClient\Http\Controllers\WebhookController;
use ArtisanBuild\MatteClient\JobHandle;
use ArtisanBuild\MatteClient\Jobs\AwaitRemovalJob;
use ArtisanBuild\MatteClient\MatteClient;
use ArtisanBuild\MatteContracts\JobStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config()->set('matte.url', 'https://matte.example');
    config()->set('matte.token', 'secret-token');
    config()->set('matte.poll_interval', 0);
    config()->set('matte.poll_timeout', 5);
});

it('submits an async removal request with bearer token and multipart fields', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'matte-image-');
    file_put_contents($path, 'image-bytes');

    Http::fake([
        'https://matte.example/v1/remove' => Http::response([
            'envelope_version' => 1,
            'job_id' => 'j1',
            'status' => 'queued',
        ], 202),
    ]);

    $handle = Matte::remove($path, ['mode' => 'grabcut']);

    expect($handle->id())->toBe('j1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://matte.example/v1/remove'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request->hasFile('image', 'image-bytes', basename($path))
        && collect($request->data())->contains(fn (array $part): bool => ($part['name'] ?? null) === 'mode' && ($part['contents'] ?? null) === 'grabcut'));
});

it('waits for completion and fetches the result bytes', function (): void {
    Http::fake([
        'https://matte.example/v1/jobs/j1' => Http::sequence()
            ->push(['envelope_version' => 1, 'job_id' => 'j1', 'status' => 'queued'])
            ->push(['envelope_version' => 1, 'job_id' => 'j1', 'status' => 'done']),
        'https://matte.example/v1/jobs/j1/result' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $handle = new JobHandle(app(MatteClient::class), 'j1');

    expect($handle->wait()->status)->toBe(JobStatus::Done)
        ->and($handle->result())->toBe('png-bytes');
});

it('submits a sync removal request and returns png bytes', function (): void {
    Http::fake([
        'https://matte.example/v1/remove?sync=1' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    expect(Matte::removeSync('raw-image-bytes'))->toBe('png-bytes');
});

it('accepts signed webhooks and rejects bad signatures', function (): void {
    Event::fake();
    config()->set('matte.webhook_path', 'matte/webhook');
    config()->set('matte.webhook_secret', 'webhook-secret');
    app('router')->post('matte/webhook', WebhookController::class);

    $body = json_encode(['job_id' => 'j1', 'status' => 'done', 'output_ref' => 's3://result'], JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'webhook-secret');

    $this->postJson('/matte/webhook', json_decode($body, true), [
        'X-Matte-Signature' => $signature,
        'X-Matte-Event' => 'job.completed',
    ])->assertNoContent();

    Event::assertDispatched(MatteRemovalCompleted::class, fn (MatteRemovalCompleted $event): bool => $event->jobId === 'j1'
        && $event->status === JobStatus::Done
        && $event->path === 's3://result');

    Event::fake();

    $this->postJson('/matte/webhook', json_decode($body, true), [
        'X-Matte-Signature' => 'sha256=bad',
    ])->assertUnauthorized();

    Event::assertNotDispatched(MatteRemovalCompleted::class);
});

it('awaits removal jobs, stores results, and dispatches completion events', function (): void {
    Event::fake();
    Storage::fake('matte-results');
    config()->set('matte.store_disk', 'matte-results');

    Http::fake([
        'https://matte.example/v1/jobs/j1' => Http::response(['envelope_version' => 1, 'job_id' => 'j1', 'status' => 'done']),
        'https://matte.example/v1/jobs/j1/result' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    (new AwaitRemovalJob('j1'))->handle(app(MatteClient::class));

    Storage::disk('matte-results')->assertExists('matte/j1.png');
    Event::assertDispatched(MatteRemovalCompleted::class, fn (MatteRemovalCompleted $event): bool => $event->jobId === 'j1'
        && $event->status === JobStatus::Done
        && $event->path === 'matte/j1.png');
});

it('does not hard-code a server url in source', function (): void {
    $source = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src')))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()))
        ->implode("\n");

    expect($source)->not->toContain('matte.example')
        ->and($source)->not->toContain('localhost');
});
