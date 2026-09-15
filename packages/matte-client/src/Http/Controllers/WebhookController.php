<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient\Http\Controllers;

use ArtisanBuild\BuiltForCloud\Exceptions\HmacKeyUnreadable;
use ArtisanBuild\BuiltForCloud\Exceptions\HmacVerificationFailed;
use ArtisanBuild\BuiltForCloud\Hmac\HmacEnvelope;
use ArtisanBuild\BuiltForCloud\Hmac\HmacVerifier;
use ArtisanBuild\MatteClient\CallbackScope;
use ArtisanBuild\MatteClient\Events\MatteRemovalCompleted;
use ArtisanBuild\MatteContracts\Exceptions\InvalidEnvelope;
use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteContracts\JobStatusEnvelope;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use RuntimeException;

final class WebhookController
{
    public function __construct(
        private readonly HmacVerifier $verifier,
        private readonly CallbackScope $scope,
    ) {}

    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();
        $signature = $request->header(HmacEnvelope::HEADER);

        if (! is_string($signature) || $signature === '') {
            return response(status: 401);
        }

        try {
            $this->verifier->verifyBound($this->scope->resolve(), $signature, $raw);
        } catch (HmacVerificationFailed|HmacKeyUnreadable|DecryptException) {
            return response(status: 401);
        } catch (RuntimeException) {
            return response(status: 503);
        }

        try {
            $payload = JobStatusEnvelope::fromJson($raw);
        } catch (InvalidEnvelope) {
            return response(status: 400);
        }

        if (! in_array($payload->status, [JobStatus::Done, JobStatus::Failed], true)) {
            return response(status: 400);
        }

        Event::dispatch(new MatteRemovalCompleted(
            jobId: $payload->jobId,
            status: $payload->status,
            path: $payload->outputRef,
            error: $payload->error,
        ));

        return response(status: 204);
    }
}
