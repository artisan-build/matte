<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteContracts;

enum Preset: string
{
    case Fast = 'fast';
    case Balanced = 'balanced';
    case Quality = 'quality';
}
