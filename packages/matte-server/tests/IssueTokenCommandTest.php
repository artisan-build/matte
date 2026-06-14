<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('issues a plaintext token and matte tokens hash line', function (): void {
    $exitCode = Artisan::call('matte:issue-token', ['id' => 'app']);
    $output = Artisan::output();

    preg_match('/Token: ([a-f0-9]{64})/', $output, $tokenMatches);
    preg_match('/^app=([a-f0-9]{64})$/m', $output, $hashMatches);

    expect($exitCode)->toBe(0)
        ->and($tokenMatches[1] ?? null)->toBeString()
        ->and($hashMatches[1] ?? null)->toBeString()
        ->and(hash('sha256', $tokenMatches[1]))->toBe($hashMatches[1]);
});
