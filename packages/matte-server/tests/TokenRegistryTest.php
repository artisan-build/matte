<?php

declare(strict_types=1);

use ArtisanBuild\MatteServer\TokenRegistry;

it('resolves known bearer tokens to app ids', function (): void {
    config()->set('matte-server.tokens', [
        ['id' => 'app', 'token_hash' => hash('sha256', 'known-token')],
    ]);

    expect(app(TokenRegistry::class)->resolve('known-token'))->toBe('app');
});

it('returns null for wrong bearer tokens', function (): void {
    config()->set('matte-server.tokens', [
        ['id' => 'app', 'token_hash' => hash('sha256', 'known-token')],
    ]);

    expect(app(TokenRegistry::class)->resolve('wrong-token'))->toBeNull();
});

it('returns null for empty bearer tokens', function (): void {
    config()->set('matte-server.tokens', [
        ['id' => 'app', 'token_hash' => hash('sha256', 'known-token')],
    ]);

    expect(app(TokenRegistry::class)->resolve(''))->toBeNull();
});
