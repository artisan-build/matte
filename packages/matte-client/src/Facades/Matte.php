<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient\Facades;

use ArtisanBuild\MatteClient\JobHandle;
use ArtisanBuild\MatteClient\MatteClient;
use ArtisanBuild\MatteContracts\JobStatusEnvelope;
use Illuminate\Support\Facades\Facade;

/**
 * @method static JobHandle remove(mixed $image, array<string, mixed> $options = [], ?string $callbackUrl = null)
 * @method static string removeSync(mixed $image, array<string, mixed> $options = [])
 * @method static JobStatusEnvelope status(string $jobId)
 * @method static string result(string $jobId)
 *
 * @see MatteClient
 */
final class Matte extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'matte';
    }
}
