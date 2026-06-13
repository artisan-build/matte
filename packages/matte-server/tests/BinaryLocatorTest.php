<?php

declare(strict_types=1);

use ArtisanBuild\MatteServer\BinaryLocator;
use ArtisanBuild\MatteServer\Exceptions\UnsupportedPlatform;

it('maps supported platforms to binary names', function (string $osFamily, string $machine, string $binary): void {
    expect(new BinaryLocator($osFamily, $machine))->binaryName()->toBe($binary);
})->with([
    'macos arm64' => ['Darwin', 'arm64', 'bg-remover-macos-arm64'],
    'linux aarch64' => ['Linux', 'aarch64', 'bg-remover-linux-arm64'],
    'linux x86_64' => ['Linux', 'x86_64', 'bg-remover-ubuntu-x86_64'],
]);

it('throws for unsupported platforms', function (string $osFamily, string $machine): void {
    expect(fn () => (new BinaryLocator($osFamily, $machine))->binaryName())
        ->toThrow(UnsupportedPlatform::class);
})->with([
    'linux armv7l' => ['Linux', 'armv7l'],
    'windows x86_64' => ['Windows', 'x86_64'],
]);

it('sets dyld library path only on darwin', function (): void {
    config()->set('matte-server.runtime_path', '/tmp/matte-runtime');

    expect(new BinaryLocator('Darwin', 'arm64'))->executionEnv()
        ->toBe(['DYLD_LIBRARY_PATH' => '/tmp/matte-runtime/bin/lib'])
        ->and(new BinaryLocator('Linux', 'x86_64'))->executionEnv()
        ->toBe([]);
});
