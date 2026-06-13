<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteContracts;

enum JobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
