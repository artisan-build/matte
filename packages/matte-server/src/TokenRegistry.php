<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

final class TokenRegistry
{
    public function resolve(string $bearerToken): ?string
    {
        if ($bearerToken === '') {
            return null;
        }

        $actual = hash('sha256', $bearerToken);

        foreach (config('matte-server.tokens', []) as $app) {
            if (! is_array($app) || ! isset($app['id'], $app['token_hash'])) {
                continue;
            }

            if (hash_equals((string) $app['token_hash'], $actual)) {
                return (string) $app['id'];
            }
        }

        return null;
    }
}
