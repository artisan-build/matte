<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\BfcHeaders;
use ArtisanBuild\MatteClient\Facades\Matte;
use ArtisanBuild\MatteClient\MatteClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('matte.url', 'https://matte.example');
    config()->set('matte.token', 'secret-token');
    config()->set('bfc-client.identity', 'matte-install-abc-123');
});

it('sends the BfC client identity alongside the token when submitting a removal', function (): void {
    Http::fake([
        'https://matte.example/v1/remove' => Http::response([
            'envelope_version' => 1,
            'job_id' => 'j1',
            'status' => 'queued',
        ], 202),
    ]);

    Matte::remove('image-bytes');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://matte.example/v1/remove'
        && $request->header(BfcHeaders::CLIENT_ID) === ['matte-install-abc-123']
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('sends the BfC client identity when polling job status', function (): void {
    Http::fake([
        'https://matte.example/v1/jobs/j1' => Http::response([
            'envelope_version' => 1,
            'job_id' => 'j1',
            'status' => 'done',
        ]),
    ]);

    app(MatteClient::class)->status('j1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://matte.example/v1/jobs/j1'
        && $request->header(BfcHeaders::CLIENT_ID) === ['matte-install-abc-123']);
});

it('sends the BfC client identity when fetching the result bytes', function (): void {
    Http::fake([
        'https://matte.example/v1/jobs/j1/result' => Http::response('png-bytes', 200),
    ]);

    app(MatteClient::class)->result('j1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://matte.example/v1/jobs/j1/result'
        && $request->header(BfcHeaders::CLIENT_ID) === ['matte-install-abc-123']);
});

it('sends exactly one identity header, resolved by bfc-client at call time', function (): void {
    Http::fake([
        'https://matte.example/v1/jobs/j1' => Http::response([
            'envelope_version' => 1,
            'job_id' => 'j1',
            'status' => 'done',
        ]),
    ]);

    config()->set('bfc-client.identity', 'set-after-boot');

    app(MatteClient::class)->status('j1');

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['set-after-boot']);
});
