<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Mcp\Support;

use Illuminate\Support\Facades\Crypt;
use Throwable;

final class OpaqueCursor
{
    /** @param array<string, string> $payload */
    public static function encode(array $payload): string
    {
        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public static function decode(?string $cursor, string $kind, string $scope): array
    {
        if ($cursor === null) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            McpInput::fail('cursor', 'The cursor is invalid.');
        }

        if (! is_array($decoded) || array_is_list($decoded)
            || ($decoded['kind'] ?? null) !== $kind
            || ($decoded['scope'] ?? null) !== $scope) {
            McpInput::fail('cursor', 'The cursor is invalid.');
        }

        return $decoded;
    }
}
