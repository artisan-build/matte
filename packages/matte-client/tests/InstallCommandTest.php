<?php

declare(strict_types=1);

it('writes matte credentials to the environment file', function (): void {
    $envPath = base_path('.env');
    $configPath = config_path('matte.php');

    if (file_exists($envPath)) {
        unlink($envPath);
    }

    if (file_exists($configPath)) {
        unlink($configPath);
    }

    $this->artisan('matte:install')
        ->expectsQuestion('Matte server URL', 'https://matte.example')
        ->expectsQuestion('Matte API token', 'api-token')
        ->assertExitCode(0);

    expect(file_get_contents($envPath))->toContain('MATTE_URL=https://matte.example')
        ->and(file_get_contents($envPath))->toContain('MATTE_TOKEN=api-token')
        ->and($configPath)->toBeFile()
        ->and(require $configPath)->toBe(require __DIR__.'/../config/matte.php');
});
