<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Contracts\UsageReporter;
use ArtisanBuild\MatteServer\MatteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports per-token matte job counts', function (): void {
    MatteJob::factory()->count(2)->create(['token_id' => 'app']);
    MatteJob::factory()->create(['token_id' => 'fallback']);
    MatteJob::factory()->create(['token_id' => null]);

    expect(app(UsageReporter::class)->perToken())->toBe([
        'app' => ['jobs' => 2],
        'fallback' => ['jobs' => 1],
    ]);
});
