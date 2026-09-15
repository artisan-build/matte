<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Actions\ActivateCredential;
use ArtisanBuild\BuiltForCloud\Actions\MintCredential;
use ArtisanBuild\BuiltForCloud\BoundCredentialScope;
use ArtisanBuild\BuiltForCloud\ClaimedHmacCredential;
use ArtisanBuild\BuiltForCloud\Contracts\HmacCredentialIssuerClient;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialMaterialRole;
use ArtisanBuild\BuiltForCloud\CredentialProtocolBinding;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Exceptions\HmacVerificationFailed;
use ArtisanBuild\BuiltForCloud\Hmac\HmacEnvelope;
use ArtisanBuild\BuiltForCloud\Hmac\HmacKeyring;
use ArtisanBuild\BuiltForCloud\Hmac\HmacSigner;
use ArtisanBuild\BuiltForCloud\Hmac\HmacVerifier;
use ArtisanBuild\BuiltForCloud\HmacCredentialTransfer;
use ArtisanBuild\BuiltForCloud\ImportedHmacSecret;
use ArtisanBuild\BuiltForCloud\IssuerHmacCutoverReceipt;
use ArtisanBuild\BuiltForCloud\MintOptions;
use ArtisanBuild\BuiltForCloud\SensitiveString;
use ArtisanBuild\MatteClient\CallbackScope;
use ArtisanBuild\MatteClient\Events\MatteRemovalCompleted;
use ArtisanBuild\MatteClient\Facades\Matte;
use ArtisanBuild\MatteClient\InstallCallbackCredential;
use ArtisanBuild\MatteClient\JobHandle;
use ArtisanBuild\MatteClient\Jobs\AwaitRemovalJob;
use ArtisanBuild\MatteClient\MatteClient;
use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteContracts\JobStatusEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('matte.url', 'https://matte.example');
    config()->set('matte.token', 'secret-token');
    config()->set('matte.poll_interval', 0);
    config()->set('matte.poll_timeout', 5);
    config()->set('matte.callback', [
        'path' => 'matte/callback',
        'subject_ref' => 'consumer-routing-identity',
        'installation' => 'consumer-installation',
        'application' => 'consumer-application',
        'audience' => 'consumer.example',
    ]);
});

it('declares the package callback purpose for bound installation issuance', function (): void {
    expect(config('built-for-cloud.credentials.app_purposes')['matte.callback'] ?? null)->toBe(CredentialPurpose::Signing->value)
        ->and(config('built-for-cloud.ui.credential_purposes'))->toContain('matte.callback');
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
        && ! collect($request->data())->contains(fn (array $part): bool => ($part['name'] ?? null) === 'callback_url')
        && collect($request->data())->contains(fn (array $part): bool => ($part['name'] ?? null) === 'mode' && ($part['contents'] ?? null) === 'grabcut'));
});

it('preserves the positional callback argument while sending an opaque destination identifier', function (): void {
    Http::fake([
        'https://matte.example/v1/remove' => Http::response([
            'job_id' => 'j1',
            'status' => 'queued',
        ], 202),
    ]);

    expect(Matte::remove('raw-image-bytes', [], 'consumer-app'))->toBeInstanceOf(JobHandle::class);

    Http::assertSent(fn (Request $request): bool => collect($request->data())
        ->contains(fn (array $part): bool => ($part['name'] ?? null) === 'callback_destination'
            && ($part['contents'] ?? null) === 'consumer-app'));
});

it('preserves the named callback argument while sending an opaque destination identifier', function (): void {
    Http::fake([
        'https://matte.example/v1/remove' => Http::response([
            'job_id' => 'j1',
            'status' => 'queued',
        ], 202),
    ]);

    expect(Matte::remove(
        image: 'raw-image-bytes',
        callbackUrl: 'consumer-app',
    ))->toBeInstanceOf(JobHandle::class);

    Http::assertSent(fn (Request $request): bool => collect($request->data())
        ->contains(fn (array $part): bool => ($part['name'] ?? null) === 'callback_destination'
            && ($part['contents'] ?? null) === 'consumer-app'));
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

it('verifies a bound callback before dispatching exactly one completion event and refuses replay', function (): void {
    Event::fake();
    $body = JobStatusEnvelope::make('test-job', JobStatus::Done, 'outputs/test.png')->toJson();
    [$header] = signedCallback($this, app(CallbackScope::class)->resolve(), $body);

    postCallback($this, $body, $header)->assertNoContent();
    try {
        app(HmacVerifier::class)->verifyBound(app(CallbackScope::class)->resolve(), $header, $body);
        $this->fail('The exact accepted stamp was not refused as a replay.');
    } catch (HmacVerificationFailed $exception) {
        expect($exception->reason)->toBe('replayed_nonce');
    }
    postCallback($this, $body, $header)->assertUnauthorized();

    Event::assertDispatched(MatteRemovalCompleted::class, fn (MatteRemovalCompleted $event): bool => $event->jobId === 'test-job'
        && $event->status === JobStatus::Done
        && $event->path === 'outputs/test.png');
    Event::assertDispatchedTimes(MatteRemovalCompleted::class, 1);
});

it('uniformly refuses invalid callback signatures and scopes without dispatching', function (): void {
    Event::fake();
    $scope = app(CallbackScope::class)->resolve();
    $body = JobStatusEnvelope::make('test-job', JobStatus::Done, 'outputs/test.png')->toJson();

    postCallback($this, $body)->assertUnauthorized();
    postCallback($this, $body, 'malformed')->assertUnauthorized();

    [$changedBodyHeader] = signedCallback($this, $scope, $body);
    postCallback($this, JobStatusEnvelope::make('changed-job', JobStatus::Done)->toJson(), $changedBodyHeader)->assertUnauthorized();

    $purposes = config('built-for-cloud.credentials.app_purposes');
    expect($purposes)->toBeArray();
    $purposes['other.callback'] = CredentialPurpose::Signing->value;
    config()->set('built-for-cloud.credentials.app_purposes', $purposes);
    config()->push('built-for-cloud.ui.credential_purposes', 'other.callback');
    $wrongPurpose = new BoundCredentialScope('other.callback', $scope->subject, $scope->installation, $scope->application, $scope->audience);
    [$wrongPurposeHeader] = signedCallback($this, $wrongPurpose, $body);
    postCallback($this, $body, $wrongPurposeHeader)->assertUnauthorized();

    foreach (['audience', 'application', 'installation'] as $field) {
        [$header, $credentialId] = signedCallback($this, $scope, $body);
        config()->set("matte.callback.{$field}", "wrong-{$field}");
        postCallback($this, $body, $header)->assertUnauthorized();
        expect(Credential::query()->findOrFail($credentialId)->last_used_at)->toBeNull()
            ->and(Cache::has('bfc:hmac:rate:'.$credentialId))->toBeFalse();
        config()->set("matte.callback.{$field}", match ($field) {
            'audience' => 'consumer.example',
            'application' => 'consumer-application',
            'installation' => 'consumer-installation',
        });
    }

    [$revokedHeader, $revokedId] = signedCallback($this, $scope, $body);
    Credential::query()->whereKey($revokedId)->update(['revoked_at' => now()]);
    postCallback($this, $body, $revokedHeader)->assertUnauthorized();

    [$expiredHeader, $expiredId] = signedCallback($this, $scope, $body);
    Credential::query()->whereKey($expiredId)->update(['expires_at' => now()->subSecond()]);
    postCallback($this, $body, $expiredHeader)->assertUnauthorized();

    Event::assertNotDispatched(MatteRemovalCompleted::class);
});

it('returns a non-5xx refusal for a verified malformed callback payload', function (): void {
    Event::fake();
    [$header] = signedCallback($this, app(CallbackScope::class)->resolve(), '{');

    postCallback($this, '{', $header)->assertBadRequest();

    Event::assertNotDispatched(MatteRemovalCompleted::class);
});

it('installs callback verification material only through the package claim seam', function (): void {
    $scope = app(CallbackScope::class)->resolve();
    $key = str_repeat('a', 64);
    $generation = 1;
    $deliveredAt = CarbonImmutable::now()->startOfSecond();
    $credentialId = (string) Str::uuid();
    $claimed = ClaimedHmacCredential::fromIssuerResponse(
        HmacCredentialTransfer::fromIssuerResponse(
            $credentialId,
            $scope,
            'hmac-sha256',
            null,
            $generation,
            app(HmacKeyring::class)->deliveryFingerprint($key, $generation),
            null,
            CredentialStatus::Pending,
            $deliveredAt,
            $deliveredAt->addSeconds(60),
        ),
        ImportedHmacSecret::fromIssuerResponse($key),
    );
    $issuer = new class($claimed) implements HmacCredentialIssuerClient
    {
        public function __construct(private readonly ClaimedHmacCredential $claimed) {}

        public function claim(BoundCredentialScope $expectedScope, SensitiveString $claimCode): ClaimedHmacCredential
        {
            $claimCode->reveal();

            return $this->claimed;
        }

        public function activate(BoundCredentialScope $expectedScope, ?string $predecessorId, string $replacementId, string $deliveryFingerprint): IssuerHmacCutoverReceipt
        {
            throw new LogicException('Not used by installation.');
        }

        public function cutoverStatus(BoundCredentialScope $expectedScope, ?string $predecessorId, string $replacementId): IssuerHmacCutoverReceipt
        {
            throw new LogicException('Not used by installation.');
        }
    };

    $installed = app(InstallCallbackCredential::class)($issuer, 'test-claim-code');

    expect($installed->credentialId)->toBe($credentialId)
        ->and(Credential::query()->findOrFail($credentialId)->secret_ciphertext)->not->toBe($key)
        ->and(CredentialProtocolBinding::query()->findOrFail($credentialId)->material_role)->toBe(CredentialMaterialRole::VerificationCopy);
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

/** @return array{string, string} */
function signedCallback(object $testCase, BoundCredentialScope $scope, string $body): array
{
    $mint = app(MintCredential::class)(
        $scope->subject,
        new MintOptions(
            kind: CredentialKind::Hmac,
            purpose: CredentialPurpose::Signing,
            codeTtlSeconds: 3600,
            boundScope: $scope,
        ),
    );
    $delivery = $testCase->postJson('/bfc/onboarding/exchange', [
        'token' => $mint->secret?->reveal(),
        'version' => 1,
    ])->assertCreated();
    $credentialId = (string) $delivery->json('credential_id');
    app(ActivateCredential::class)($credentialId, (string) $delivery->json('delivery_fingerprint'));

    $header = app(HmacSigner::class)->signBound($scope, $body, 'matte.removal.completed');
    [$envelope] = HmacEnvelope::parse($header);

    return [$header, $envelope->keyId];
}

function postCallback(object $testCase, string $body, ?string $header = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($header !== null) {
        $server['HTTP_BFC_SIGNATURE'] = $header;
    }

    return $testCase->call('POST', '/matte/callback', server: $server, content: $body);
}

it('does not hard-code a server url in source', function (): void {
    $source = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src')))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()))
        ->implode("\n");

    expect($source)->not->toContain('matte.example')
        ->and($source)->not->toContain('localhost');
});
