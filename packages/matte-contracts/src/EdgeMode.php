<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteContracts;

enum EdgeMode: string
{
    case Blur = 'blur';
    case Bilateral = 'bilateral';
    case Guided = 'guided';
}
