<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient\Http\Controllers;

use ArtisanBuild\MatteClient\Events\MatteRemovalCompleted;
use ArtisanBuild\MatteContracts\JobStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use JsonException;

final class WebhookController
{
    public function __invoke(Request $request): Response
    {
        $secret = config('matte.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response(status: 403);
        }

        $raw = $request->getContent();
        $signature = $request->header('X-Matte-Signature');
        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);

        if (! is_string($signature) || ! hash_equals($expected, $signature)) {
            return response(status: 401);
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response(status: 400);
        }

        if (! is_array($payload) || ! is_string($payload['job_id'] ?? null) || ! is_string($payload['status'] ?? null)) {
            return response(status: 400);
        }

        $status = JobStatus::tryFrom($payload['status']);

        if ($status === null) {
            return response(status: 400);
        }

        Event::dispatch(new MatteRemovalCompleted(
            jobId: $payload['job_id'],
            status: $status,
            path: is_string($payload['output_ref'] ?? null) ? $payload['output_ref'] : null,
            error: is_string($payload['error'] ?? null) ? $payload['error'] : null,
        ));

        return response(status: 204);
    }
}
