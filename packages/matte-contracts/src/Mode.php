<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteContracts;

enum Mode: string
{
    case Ml = 'ml';
    case Grabcut = 'grabcut';
}
