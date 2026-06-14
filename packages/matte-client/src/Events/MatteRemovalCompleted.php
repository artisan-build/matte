<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient\Events;

use ArtisanBuild\MatteContracts\JobStatus;

final readonly class MatteRemovalCompleted
{
    public function __construct(
        public string $jobId,
        public JobStatus $status,
        public ?string $path = null,
        public ?string $error = null,
    ) {}
}
