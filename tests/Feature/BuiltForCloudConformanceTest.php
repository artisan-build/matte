<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Commands\ConsoleReKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleRetireKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\CreateAdminCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialActivateCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialListCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialMintCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRevokeCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRotateCommand;
use ArtisanBuild\BuiltForCloud\Commands\HmacRewrapCommand;
use ArtisanBuild\BuiltForCloud\Commands\InstallOperatorCredentialCommand;
use ArtisanBuild\BuiltForCloud\Commands\OutboxDrainCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipMintClaimCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipRemintOwnerTokenCommand;
use ArtisanBuild\BuiltForCloud\Commands\PruneCredentialAuthorizationsCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootProvisionCommand;
use ArtisanBuild\BuiltForCloud\Commands\SubjectOffboardCommand;
use ArtisanBuild\BuiltForCloud\Commands\WarnExpiringCredentialsCommand;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Jobs\DeliverOwnershipWebhook;
use ArtisanBuild\BuiltForCloud\Testing\ConsumerConformance;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FleetConformance;
use ArtisanBuild\MatteServer\Commands\DoctorCommand;
use ArtisanBuild\MatteServer\Commands\ProvisionBinaryCommand;
use ArtisanBuild\MatteServer\Commands\RemoveCommand;
use ArtisanBuild\MatteServer\Jobs\RemoveBackgroundJob;
use Composer\InstalledVersions;

uses(ContractAssertions::class);

it('passes Built for Cloud fleet conformance', function (): void {
    $packageRoot = InstalledVersions::getInstallPath('artisan-build/built-for-cloud');
    expect($packageRoot)->toBeString();

    $sorted = static function (array $members): array {
        sort($members);

        return $members;
    };

    // Package issue #123 leaves no scanner exclusion API, so bootstrap/cache
    // is omitted by declaring the executable bootstrap file as a provider.
    $sourceRoots = [
        app_path(),
        config_path(),
        database_path('migrations'),
        base_path('packages/matte-server/config'),
        base_path('packages/matte-server/database/migrations'),
        base_path('packages/matte-server/routes'),
        base_path('packages/matte-server/src'),
        base_path('routes'),
    ];
    sort($sourceRoots);
    $providerFiles = [
        app_path('Providers/AppServiceProvider.php'),
        base_path('bootstrap/app.php'),
        $packageRoot.'/src/BuiltForCloudServiceProvider.php',
        base_path('packages/matte-server/src/MatteServerServiceProvider.php'),
    ];
    sort($providerFiles);

    $expected = [
        'runtime.meta' => [],
        'runtime.auth_schema' => [],
        'runtime.credential_listing' => [],
        'runtime.transport_parity' => [],
        'thin_host' => [],
        'credential_paths' => $sorted([
            'path:Basic|ArtisanBuild\BuiltForCloud\Auth\BasicAuthenticator',
            'path:Bearer|ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator',
            'path:HMAC|Http\Middleware\VerifyHmacSignature+Hmac\HmacVerifier',
            'path:MCP|Http\Middleware\AuthenticateMcp:store-bearer+v4.public',
            'path:asymmetric|Actions\MintCredential::mintEnrollment+CompleteAsymmetricEnrollment+AsymmetricVerificationKeys',
            'path:device|Http\Controllers\DeviceAuthorizations+Actions\StartDeviceAuthorization/DecideDeviceAuthorization/PollDeviceAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
            'path:enrollment|OnboardingToken+POST:/bfc/claim,/bfc/onboarding/issue,/exchange,/verify',
            'path:loopback|Http\Controllers\LoopbackAuthorizations+Actions\StartLoopbackAuthorization/DecideLoopbackAuthorization/ExchangeLoopbackAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
            'path:system|SubjectType::Operator/Application/Installation+AuditActorType::CliOperator',
        ]),
        'credential_writers' => $sorted([
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSecretBearing',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithPendingSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithSecret',
            'ArtisanBuild\BuiltForCloud\OwnerCredentialMinter::mintFromHash',
            'ArtisanBuild\BuiltForCloud\UnifiedStoreCredentialMinter::mint',
        ]),
        'legacy_removal' => [],
        'system_authority' => $sorted([
            ConsoleReKeyCommand::class,
            ConsoleRetireKeyCommand::class,
            CreateAdminCommand::class,
            CredentialActivateCommand::class,
            CredentialListCommand::class,
            CredentialMintCommand::class,
            CredentialRevokeCommand::class,
            CredentialRotateCommand::class,
            HmacRewrapCommand::class,
            InstallOperatorCredentialCommand::class,
            OutboxDrainCommand::class,
            OwnershipMintClaimCommand::class,
            OwnershipRemintOwnerTokenCommand::class,
            PruneCredentialAuthorizationsCommand::class,
            SigningRootProvisionCommand::class,
            SubjectOffboardCommand::class,
            WarnExpiringCredentialsCommand::class,
            DeliverOwnershipWebhook::class,
            DoctorCommand::class,
            ProvisionBinaryCommand::class,
            RemoveCommand::class,
            RemoveBackgroundJob::class,
            'Closure@package/src/SystemAuthoritySchedule.php:27',
        ]),
        'no_signing_path' => [],
        'ui_config_reads' => $sorted([
            'ArtisanBuild\BuiltForCloud\AppPurposeRegistry|built-for-cloud.credentials.app_purposes|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\ManageTransitions|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.installation_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.member_management|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.personal_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.session_management|1',
            'ArtisanBuild\BuiltForCloud\LandingManifest|built-for-cloud.manifest|1',
            'ArtisanBuild\BuiltForCloud\LandingPageRegistrar|built-for-cloud.ui.landing_page|1',
            'ArtisanBuild\BuiltForCloud\UiCredentialPurposes|built-for-cloud.ui.credential_purposes|1',
        ]),
        'mcp_delegated' => [],
    ];

    $report = (new FleetConformance($this))->assert(new ConsumerConformance(
        consumer: 'matte',
        consumerRoot: base_path(),
        packageRoot: $packageRoot,
        sourceRoots: $sourceRoots,
        providerFiles: $providerFiles,
        runtimeAssertions: ['auth_schema', 'credential_listing', 'meta', 'transport_parity'],
        capabilities: ['credentials', 'tokens'],
        purposeMappings: [
            'matte.callback' => CredentialPurpose::Signing,
            'matte.remove' => CredentialPurpose::Consumption,
        ],
        mcpServer: null,
        expected: $expected,
    ));

    expect($report->passed)->toBeTrue();
});

it('matches the canonical Matte manifest and preserves the public routes', function (): void {
    $this->assertBuiltForCloudManifestMatches([
        'name' => 'Matte',
        'slug' => 'matte',
        'description' => 'Background removal as an API you own: submit an image, poll the job, fetch a transparent PNG.',
        'icon' => 'https://scalpels.app/img/products/transparent/matte.svg',
        'product_url' => 'https://scalpels.app/products/matte',
    ]);

    $this->getJson('/')
        ->assertOk()
        ->assertExactJson(['name' => 'Matte', 'status' => 'ok']);
    $this->get('/up')->assertOk();
});

it('keeps supported guidance on unified local credential commands', function (): void {
    $supportedFiles = [
        base_path('.claude/skills/provisioning-matte-on-cloud/SKILL.md'),
        base_path('.claude/skills/provisioning-matte-on-cloud/reference/resource-plan.md'),
        base_path('.env.example'),
        base_path('README.md'),
        base_path('packages/matte-client/README.md'),
        base_path('packages/matte-client/docs/integrate/default.md'),
        base_path('packages/matte-server/README.md'),
        base_path('packages/matte-server/config/matte-server.php'),
    ];

    foreach ($supportedFiles as $path) {
        $contents = file_get_contents($path);

        expect($contents)
            ->toBeString()
            ->not->toContain(
                'FALLBACK_TOKEN',
                'fallback_token',
                'TokenRegistry',
                'ApiToken',
                'api_tokens',
                'token:create',
                'token:rotate',
                'token:revoke',
                'token:list',
                'token:usage',
            );

        preg_match_all('/php artisan bfc:credential:(?:mint|rotate|revoke)[^\n\x60]*/', $contents, $stateChangingCommands);

        foreach ($stateChangingCommands[0] as $command) {
            expect($command)->toContain('--local');
        }
    }
});
