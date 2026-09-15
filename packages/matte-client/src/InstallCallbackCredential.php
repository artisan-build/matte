<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient;

use ArtisanBuild\BuiltForCloud\Actions\InstallHmacCredentialFromClaim;
use ArtisanBuild\BuiltForCloud\Contracts\HmacCredentialIssuerClient;
use ArtisanBuild\BuiltForCloud\InstalledHmacCredential;
use SensitiveParameter;

final readonly class InstallCallbackCredential
{
    public function __construct(
        private InstallHmacCredentialFromClaim $install,
        private CallbackScope $scope,
    ) {}

    public function __invoke(
        HmacCredentialIssuerClient $trustedIssuer,
        #[SensitiveParameter] string $claimCode,
    ): InstalledHmacCredential {
        return ($this->install)($this->scope->resolve(), $trustedIssuer, $claimCode);
    }
}
