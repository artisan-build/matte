<?php

declare(strict_types=1);

it('writes matte credentials to the environment file', function (): void {
    $envPath = base_path('.env');

    if (file_exists($envPath)) {
        unlink($envPath);
    }

    $this->artisan('matte:install')
        ->expectsQuestion('Matte server URL', 'https://matte.example')
        ->expectsQuestion('Matte API token', 'api-token')
        ->expectsQuestion('Matte webhook secret (optional)', '')
        ->assertExitCode(0);

    expect(file_get_contents($envPath))->toContain('MATTE_URL=https://matte.example')
        ->and(file_get_contents($envPath))->toContain('MATTE_TOKEN=api-token');
});
