<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteContracts;

use ArtisanBuild\MatteContracts\Exceptions\InvalidEnvelope;

final readonly class RemovalOptions
{
    public function __construct(
        public Mode $mode = Mode::Ml,
        public Preset $preset = Preset::Balanced,
        public ?string $model = null,
        public ?EdgeMode $edgeMode = null,
        public ?int $iterations = null,
        public ?int $margin = null,
    ) {
        self::assertPositiveInteger($this->iterations, 'iterations');
        self::assertPositiveInteger($this->margin, 'margin');
    }

    /**
     * @return array{mode: string, preset: string, model?: string, edge_mode?: string, iterations?: int, margin?: int}
     */
    public function toArray(): array
    {
        $data = [
            'mode' => $this->mode->value,
            'preset' => $this->preset->value,
        ];

        if ($this->model !== null) {
            $data['model'] = $this->model;
        }

        if ($this->edgeMode !== null) {
            $data['edge_mode'] = $this->edgeMode->value;
        }

        if ($this->iterations !== null) {
            $data['iterations'] = $this->iterations;
        }

        if ($this->margin !== null) {
            $data['margin'] = $this->margin;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mode: self::modeFrom($data['mode'] ?? Mode::Ml->value),
            preset: self::presetFrom($data['preset'] ?? Preset::Balanced->value),
            model: self::optionalString($data, 'model'),
            edgeMode: self::optionalEdgeMode($data),
            iterations: self::optionalPositiveInteger($data, 'iterations'),
            margin: self::optionalPositiveInteger($data, 'margin'),
        );
    }

    private static function modeFrom(mixed $value): Mode
    {
        if (! is_string($value) || ($mode = Mode::tryFrom($value)) === null) {
            throw new InvalidEnvelope('Removal option mode is malformed.');
        }

        return $mode;
    }

    private static function presetFrom(mixed $value): Preset
    {
        if (! is_string($value) || ($preset = Preset::tryFrom($value)) === null) {
            throw new InvalidEnvelope('Removal option preset is malformed.');
        }

        return $preset;
    }

    private static function optionalEdgeMode(array $data): ?EdgeMode
    {
        if (! array_key_exists('edge_mode', $data) || $data['edge_mode'] === null) {
            return null;
        }

        if (! is_string($data['edge_mode']) || ($edgeMode = EdgeMode::tryFrom($data['edge_mode'])) === null) {
            throw new InvalidEnvelope('Removal option edge mode is malformed.');
        }

        return $edgeMode;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_string($data[$key])) {
            throw new InvalidEnvelope(sprintf('Removal option %s is malformed.', $key));
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalPositiveInteger(array $data, string $key): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_int($data[$key])) {
            throw new InvalidEnvelope(sprintf('Removal option %s is malformed.', $key));
        }

        self::assertPositiveInteger($data[$key], $key);

        return $data[$key];
    }

    private static function assertPositiveInteger(?int $value, string $key): void
    {
        if ($value !== null && $value <= 0) {
            throw new InvalidEnvelope(sprintf('Removal option %s must be a positive integer.', $key));
        }
    }
}
