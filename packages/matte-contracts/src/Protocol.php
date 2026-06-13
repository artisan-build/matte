<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteContracts;

use ArtisanBuild\MatteContracts\Exceptions\InvalidEnvelope;

final class Protocol
{
    public const int ENVELOPE_VERSION = 1;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function versionFrom(array $data): int
    {
        if (! array_key_exists('envelope_version', $data) || ! is_numeric($data['envelope_version'])) {
            throw new InvalidEnvelope('Envelope version is missing or malformed.');
        }

        return (int) $data['envelope_version'];
    }

    public static function isSupported(int $version): bool
    {
        return $version <= self::ENVELOPE_VERSION;
    }
}
