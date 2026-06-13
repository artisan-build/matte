<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Database\Factories;

use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteContracts\Mode;
use ArtisanBuild\MatteContracts\Preset;
use ArtisanBuild\MatteServer\MatteJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatteJob>
 */
final class MatteJobFactory extends Factory
{
    protected $model = MatteJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'input_ref' => 'inputs/'.$this->faker->uuid().'.png',
            'output_ref' => null,
            'mode' => Mode::Grabcut->value,
            'preset' => Preset::Balanced->value,
            'model' => null,
            'status' => JobStatus::Queued,
            'error' => null,
        ];
    }

    public function done(): self
    {
        return $this->state(fn (): array => [
            'output_ref' => 'outputs/'.$this->faker->uuid().'.png',
            'status' => JobStatus::Done,
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => JobStatus::Failed,
            'error' => $this->faker->sentence(),
        ]);
    }
}
