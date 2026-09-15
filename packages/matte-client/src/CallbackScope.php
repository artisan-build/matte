<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient;

use ArtisanBuild\BuiltForCloud\BoundCredentialScope;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use RuntimeException;

final class CallbackScope
{
    public const string APP_PURPOSE = 'matte.callback';

    public function resolve(): BoundCredentialScope
    {
        $scope = config('matte.callback');

        if (! is_array($scope)) {
            throw new RuntimeException('The Matte callback scope is not configured.');
        }

        foreach (['subject_ref', 'installation', 'application', 'audience'] as $key) {
            if (! is_string($scope[$key] ?? null) || $scope[$key] === '') {
                throw new RuntimeException('The Matte callback scope is not configured.');
            }
        }

        return new BoundCredentialScope(
            appPurpose: self::APP_PURPOSE,
            subject: new Subject(SubjectType::Installation, $scope['subject_ref']),
            installation: $scope['installation'],
            application: $scope['application'],
            audience: $scope['audience'],
        );
    }
}
