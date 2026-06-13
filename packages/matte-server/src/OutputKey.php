<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

use ArtisanBuild\MatteContracts\RemovalOptions;

final class OutputKey
{
    public static function for(string $inputBytesOrPath, RemovalOptions $options): string
    {
        return 'outputs/'.hash('sha256', $inputBytesOrPath.serialize($options)).'.png';
    }
}
