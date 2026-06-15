<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

use ArtisanBuild\BuiltForCloud\Contracts\UsageReporter;
use Illuminate\Support\Facades\DB;

final class MatteJobUsageReporter implements UsageReporter
{
    /**
     * @return array<string, array{jobs: int}>
     */
    public function perToken(): array
    {
        return DB::table('matte_jobs')
            ->select('token_id', DB::raw('count(*) as jobs'))
            ->whereNotNull('token_id')
            ->groupBy('token_id')
            ->orderBy('token_id')
            ->pluck('jobs', 'token_id')
            ->map(fn (int|string $jobs): array => ['jobs' => (int) $jobs])
            ->all();
    }
}
