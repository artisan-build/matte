<?php

declare(strict_types=1);

use ArtisanBuild\MatteContracts\Mode;
use ArtisanBuild\MatteContracts\Preset;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\OutputKey;

it('is deterministic for the same input and options', function (): void {
    $options = new RemovalOptions(mode: Mode::Grabcut, preset: Preset::Balanced);

    expect(OutputKey::for('image-bytes', $options))
        ->toBe(OutputKey::for('image-bytes', $options))
        ->toStartWith('outputs/')
        ->toEndWith('.png');
});

it('changes when options change', function (): void {
    expect(OutputKey::for('image-bytes', new RemovalOptions(mode: Mode::Grabcut, preset: Preset::Balanced)))
        ->not->toBe(OutputKey::for('image-bytes', new RemovalOptions(mode: Mode::Grabcut, preset: Preset::Fast)));
});
