<?php

declare(strict_types=1);

use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteServer\MatteJob;

it('persists jobs with status enum casts', function (): void {
    $this->artisan('migrate')->assertExitCode(0);

    $job = MatteJob::factory()->create();

    expect($job->exists)->toBeTrue()
        ->and($job->getKey())->toBeString()
        ->and($job->refresh()->status)->toBe(JobStatus::Queued);
});
