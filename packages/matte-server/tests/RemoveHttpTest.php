<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\ManagedAuthClient;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteContracts\Protocol;
use ArtisanBuild\MatteServer\BinaryLocator;
use ArtisanBuild\MatteServer\Jobs\RemoveBackgroundJob;
use ArtisanBuild\MatteServer\MatteJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('matte-server.disk', 'matte-test');

    $this->knownCredential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'matte-automation',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('uniformly denies invalid credentials before product work or data access', function (string $credentialCase, string $endpoint): void {
    Queue::fake();
    Storage::fake('matte-test');

    $matteJob = $endpoint === 'result'
        ? MatteJob::factory()->done()->create(['output_ref' => 'outputs/private.png'])
        : MatteJob::factory()->failed()->create(['error' => 'private failure detail']);
    Storage::disk('matte-test')->put('outputs/private.png', 'private-png-bytes');

    $request = match ($credentialCase) {
        'missing' => $this,
        'unknown' => $this->withToken('unknown-secret'),
        'revoked' => $this->actingAsCredential($this->mintCredential([
            'purpose' => CredentialPurpose::Consumption,
            'subject_type' => SubjectType::Installation,
            'subject_ref' => 'revoked-installation',
            'revoked_at' => now(),
        ])),
        'wrong-purpose' => $this->actingAsCredential($this->mintCredential([
            'purpose' => CredentialPurpose::SystemDeployment,
            'subject_type' => SubjectType::Application,
            'subject_ref' => 'wrong-purpose',
        ])),
    };

    $response = match ($endpoint) {
        'submit' => $request->postJson('/v1/remove', ['image' => UploadedFile::fake()->image('x.png')]),
        'status' => $request->getJson('/v1/jobs/'.$matteJob->id),
        'result' => $request->getJson('/v1/jobs/'.$matteJob->id.'/result'),
    };

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Unauthorized.']);
    expect($response->getContent())
        ->not->toContain('private failure detail')
        ->not->toContain('private-png-bytes');
    Queue::assertNothingPushed();
})->with([
    ['missing', 'submit'],
    ['unknown', 'submit'],
    ['revoked', 'submit'],
    ['wrong-purpose', 'submit'],
    ['missing', 'status'],
    ['unknown', 'status'],
    ['revoked', 'status'],
    ['wrong-purpose', 'status'],
    ['missing', 'result'],
    ['unknown', 'result'],
    ['revoked', 'result'],
    ['wrong-purpose', 'result'],
]);

it('allows every account role to submit asynchronous work', function (UserRole $role): void {
    Queue::fake();
    Storage::fake('matte-test');
    $credential = accountCredential($this, $role);

    $response = $this->actingAsCredential($credential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('x.png'),
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('envelope_version', Protocol::ENVELOPE_VERSION)
        ->assertJsonPath('status', 'queued')
        ->assertJsonStructure(['job_id']);
    expect(MatteJob::query()->find($response->json('job_id'))?->status)->toBe(JobStatus::Queued);
    Queue::assertPushed(RemoveBackgroundJob::class);
})->with(UserRole::cases());

it('allows every account role to submit synchronous work', function (UserRole $role): void {
    Queue::fake();
    Storage::fake('matte-test');
    $credential = accountCredential($this, $role);
    $runtimePath = fakeConverterRuntime();
    Process::fake([
        'otool *' => Process::result(),
        'ldd *' => Process::result(output: 'libonnxruntime.so => /tmp/libonnxruntime.so'),
    ]);

    try {
        $response = $this->actingAsCredential($credential)->post('/v1/remove?sync=1', [
            'image' => UploadedFile::fake()->image('x.png'),
            'mode' => 'grabcut',
        ], ['Accept' => 'application/json']);

        $response->assertSuccessful()->assertHeader('Content-Type', 'image/png');
        expect($response->getContent())->not->toBeEmpty();
        Queue::assertNothingPushed();
    } finally {
        removeDirectory($runtimePath);
    }
})->with(UserRole::cases());

it('allows another account member to inspect and download an installation job', function (): void {
    Queue::fake();
    Storage::fake('matte-test');
    $creator = accountCredential($this, UserRole::Member);
    $reader = accountCredential($this, UserRole::Admin);

    $submitted = $this->actingAsCredential($creator)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('x.png'),
    ])->assertStatus(202);
    $matteJob = MatteJob::query()->findOrFail($submitted->json('job_id'));
    $matteJob->forceFill([
        'status' => JobStatus::Done,
        'output_ref' => 'outputs/result.png',
    ])->save();
    Storage::disk('matte-test')->put('outputs/result.png', 'png-bytes');

    $this->actingAsCredential($reader)->getJson('/v1/jobs/'.$matteJob->id)
        ->assertSuccessful()
        ->assertJsonPath('status', 'done');
    $download = $this->actingAsCredential($reader)->get('/v1/jobs/'.$matteJob->id.'/result');
    $download->assertSuccessful()->assertHeader('Content-Type', 'image/png');
    expect($download->getContent())->toBe('png-bytes');
});

it('allows installation automation without admitting other unbound principals', function (): void {
    Queue::fake();
    Storage::fake('matte-test');

    $this->actingAsCredential($this->knownCredential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('x.png'),
    ])->assertStatus(202);

    $wrongPrincipal = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'other-installation-principal',
    ]);
    $this->actingAsCredential($wrongPrincipal)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('x.png'),
    ])->assertUnauthorized();

    expect($this->knownCredential->credential->refresh()->last_used_at)->not->toBeNull()
        ->and($this->knownCredential->credential->user_id)->toBeNull()
        ->and($wrongPrincipal->credential->refresh()->last_used_at)->toBeNull();
    $this->actingAsCredential($this->knownCredential)->getJson('/bfc/credentials')->assertUnauthorized();
    $this->actingAsCredential($this->knownCredential)->getJson('/bfc/console/vitals')->assertUnauthorized();
    Queue::assertPushedTimes(RemoveBackgroundJob::class, 1);
});

it('applies managed demotion removal and restoration while installation automation survives', function (): void {
    CarbonImmutable::setTestNow('2026-09-15T12:00:00+00:00');
    configureManagedMatteAuthority();
    [$user, $credential] = managedAccountCredential($this, UserRole::Admin);
    Queue::fake();
    Storage::fake('matte-test');
    $confirmation = [];
    fakeMatteManagedConfirmation($confirmation);

    $this->actingAsCredential($credential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('admin.png'),
    ])->assertStatus(202);

    $confirmation = ['role' => UserRole::Member->value, 'roster_version' => 101, 'response_sequence' => 101];
    CarbonImmutable::setTestNow('2026-09-15T12:05:00+00:00');
    $this->actingAsCredential($credential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('member.png'),
    ])->assertStatus(202);
    expect($user->refresh()->role)->toBe(UserRole::Member->value);

    $confirmation = ['membership_status' => 'removed', 'roster_version' => 102, 'response_sequence' => 102];
    CarbonImmutable::setTestNow('2026-09-15T12:10:00+00:00');
    $this->actingAsCredential($credential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('removed.png'),
    ])->assertUnauthorized();

    $this->actingAsCredential($this->knownCredential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('automation.png'),
    ])->assertStatus(202);

    $confirmation = ['membership_status' => 'active', 'role' => UserRole::Member->value, 'roster_version' => 103, 'response_sequence' => 103];
    $restoredCredential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => (string) $user->scalpels_id,
        'user_id' => (string) $user->id,
    ]);
    CarbonImmutable::setTestNow('2026-09-15T12:15:00+00:00');
    $this->actingAsCredential($restoredCredential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('restored.png'),
    ])->assertStatus(202);

    expect($user->refresh()->status)->toBe('active')
        ->and($user->managed_membership_status)->toBe('active')
        ->and($credential->credential->refresh()->revoked_at)->not->toBeNull()
        ->and($this->knownCredential->credential->refresh()->revoked_at)->toBeNull();
    Queue::assertPushedTimes(RemoveBackgroundJob::class, 4);
});

it('denies an account principal bound to another managed installation', function (): void {
    CarbonImmutable::setTestNow('2026-09-15T12:00:00+00:00');
    configureManagedMatteAuthority();
    [, $credential] = managedAccountCredential($this, UserRole::Member, [
        'scalpels_connection_id' => 'other-installation',
    ]);
    Queue::fake();
    Storage::fake('matte-test');
    Http::preventStrayRequests();

    $this->actingAsCredential($credential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('foreign.png'),
    ])->assertUnauthorized();

    Queue::assertNothingPushed();
    expect($credential->credential->refresh()->last_used_at)->toBeNull();
});

it('rejects unknown account roles before dispatch or usage', function (): void {
    Queue::fake();
    Storage::fake('matte-test');
    $credential = accountCredential($this, 'unknown');

    $this->actingAsCredential($credential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('x.png'),
    ])->assertUnauthorized();

    Queue::assertNothingPushed();
    expect($credential->credential->refresh()->last_used_at)->toBeNull();
});

it('rejects non-empty callbacks before storage or dispatch', function (): void {
    Queue::fake();
    Storage::fake('matte-test');

    $this->actingAsCredential($this->knownCredential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('x.png'),
        'callback_url' => 'https://example.test/callback',
    ])->assertUnprocessable()
        ->assertJsonFragment(['message' => 'The callback url field is prohibited.']);

    expect(MatteJob::query()->count())->toBe(0);
    Storage::disk('matte-test')->assertDirectoryEmpty('/');
    Queue::assertNothingPushed();
});

it('preserves request validation and deterministic status and result responses', function (): void {
    Storage::fake('matte-test');

    $this->actingAsCredential($this->knownCredential)->postJson('/v1/remove', [
        'envelope_version' => Protocol::ENVELOPE_VERSION + 1,
        'image' => UploadedFile::fake()->image('x.png'),
    ])->assertUnprocessable();
    $this->actingAsCredential($this->knownCredential)->postJson('/v1/remove', [
        'image' => UploadedFile::fake()->image('x.png'),
        'mode' => 'banana',
    ])->assertUnprocessable();

    $queued = MatteJob::factory()->create();
    $this->actingAsCredential($this->knownCredential)->getJson('/v1/jobs/'.$queued->id)
        ->assertSuccessful()
        ->assertJson([
            'envelope_version' => Protocol::ENVELOPE_VERSION,
            'job_id' => $queued->id,
            'status' => 'queued',
        ]);
    $this->actingAsCredential($this->knownCredential)->getJson('/v1/jobs/'.$queued->id.'/result')
        ->assertStatus(409)
        ->assertJson(['message' => 'Job is not complete.', 'status' => 'queued']);
    $this->actingAsCredential($this->knownCredential)->getJson('/v1/jobs/missing')->assertNotFound();
    $this->actingAsCredential($this->knownCredential)->getJson('/v1/jobs/missing/result')->assertNotFound();

    $done = MatteJob::factory()->done()->create(['output_ref' => 'outputs/result.png']);
    Storage::disk('matte-test')->put('outputs/result.png', 'png-bytes');
    $response = $this->actingAsCredential($this->knownCredential)->get('/v1/jobs/'.$done->id.'/result');
    $response->assertSuccessful()->assertHeader('Content-Type', 'image/png');
    expect($response->getContent())->toBe('png-bytes');
});

it('uses the forward schema without mutable credential-name attribution', function (): void {
    expect(Schema::hasColumn('matte_jobs', 'token_id'))->toBeFalse();
});

function accountCredential(object $testCase, UserRole|string $role): MintedTestCredential
{
    $suffix = bin2hex(random_bytes(8));
    $user = User::query()->create([
        'name' => 'Matte '.$suffix,
        'email' => "matte-{$suffix}@example.test",
    ]);
    $user->forceFill(['role' => $role instanceof UserRole ? $role->value : $role])->save();

    return $testCase->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'matte-account-'.$user->id,
        'user_id' => (string) $user->id,
    ]);
}

function configureManagedMatteAuthority(): void
{
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'mode' => AuthorityMode::Managed->value,
        'generation' => 7,
        'issuer' => 'https://issuer.example.test',
        'connection_id' => 'matte-connection',
        'organization_id' => 'matte-organization',
        'installation_id' => 'matte-installation',
        'authority_base_url' => 'https://authority.example.test',
        'managed_connection_status' => 'active',
        'managed_connection_generation' => 7,
        'managed_connection_roster_version' => 100,
        'managed_connection_response_sequence' => 100,
        'updated_at' => now(),
    ]);
    config()->set('built-for-cloud.managed.client_secret', 'managed-client-secret');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{User, MintedTestCredential}
 */
function managedAccountCredential(object $testCase, UserRole $role, array $overrides = []): array
{
    $suffix = bin2hex(random_bytes(8));
    $user = User::query()->create([
        'name' => 'Managed Matte '.$suffix,
        'email' => "managed-matte-{$suffix}@example.test",
    ]);
    $user->forceFill(array_merge([
        'role' => $role->value,
        'status' => 'active',
        'scalpels_issuer' => 'https://issuer.example.test',
        'scalpels_connection_id' => 'matte-connection',
        'scalpels_id' => 'matte-user-'.$suffix,
        'membership_confirmed_at' => now(),
        'membership_checked_at' => now(),
        'membership_response_at' => now(),
        'managed_membership_status' => 'active',
        'managed_membership_role' => $role->value,
        'managed_membership_generation' => 7,
        'managed_membership_roster_version' => 100,
        'managed_membership_response_sequence' => 100,
        'managed_membership_responded_at' => now(),
    ], $overrides))->save();

    return [$user->refresh(), $testCase->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => (string) $user->scalpels_id,
        'user_id' => (string) $user->id,
    ])];
}

/** @param array<string, mixed> $overrides */
function fakeMatteManagedConfirmation(array &$overrides): void
{
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) use (&$overrides): mixed {
        expect(parse_url($request->url(), PHP_URL_PATH))->toBe('/managed-auth/v1/memberships/confirm');
        $body = $request->data();

        return Http::response(array_merge([
            'contract_version' => ManagedAuthClient::CONTRACT_VERSION,
            'issuer' => 'https://issuer.example.test',
            'connection_id' => 'matte-connection',
            'organization_id' => 'matte-organization',
            'installation_id' => 'matte-installation',
            'authority_generation' => 7,
            'scalpels_id' => $body['scalpels_id'],
            'membership_status' => 'active',
            'connection_status' => 'active',
            'role' => UserRole::Admin->value,
            'roster_version' => 100,
            'response_sequence' => 100,
            'responded_at' => now()->toAtomString(),
        ], $overrides));
    });
}

function fakeConverterRuntime(): string
{
    $runtimePath = sys_get_temp_dir().'/matte-http-auth-test-'.bin2hex(random_bytes(6));
    config()->set('matte-server.runtime_path', $runtimePath);
    $locator = new BinaryLocator(PHP_OS_FAMILY, php_uname('m'));
    mkdir(dirname($locator->binaryPath()), 0777, true);
    file_put_contents($locator->binaryPath(), <<<'SH'
#!/bin/sh
while [ "$#" -gt 0 ]; do
    case "$1" in
        -i) input="$2"; shift 2 ;;
        -o) output="$2"; shift 2 ;;
        *) shift ;;
    esac
done
cp "$input" "$output"
SH);
    chmod($locator->binaryPath(), 0755);

    return $runtimePath;
}
