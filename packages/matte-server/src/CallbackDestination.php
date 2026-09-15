<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

use ArtisanBuild\BuiltForCloud\BoundCredentialScope;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;

final readonly class CallbackDestination
{
    public const string APP_PURPOSE = 'matte.callback';

    public function __construct(
        public string $url,
        public BoundCredentialScope $scope,
    ) {}

    public static function resolve(string $identifier): ?self
    {
        $destinations = config('matte-server.callback.destinations', []);
        $destination = is_array($destinations) ? ($destinations[$identifier] ?? null) : null;

        if (! is_array($destination)) {
            return null;
        }

        foreach (['url', 'subject_ref', 'installation', 'application', 'audience'] as $key) {
            if (! is_string($destination[$key] ?? null) || $destination[$key] === '') {
                return null;
            }
        }

        return new self(
            url: $destination['url'],
            scope: new BoundCredentialScope(
                appPurpose: self::APP_PURPOSE,
                subject: new Subject(SubjectType::Installation, $destination['subject_ref']),
                installation: $destination['installation'],
                application: $destination['application'],
                audience: $destination['audience'],
            ),
        );
    }
}
