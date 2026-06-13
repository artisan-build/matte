<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteContracts;

use ArtisanBuild\MatteContracts\Exceptions\InvalidEnvelope;
use JsonException;

final readonly class RemovalRequest
{
    public function __construct(
        public RemovalOptions $options,
        public ?string $idempotencyKey = null,
        public ?string $callbackUrl = null,
        public int $envelopeVersion = Protocol::ENVELOPE_VERSION,
    ) {}

    public static function make(
        RemovalOptions $options,
        ?string $idempotencyKey = null,
        ?string $callbackUrl = null,
    ): self {
        return new self($options, $idempotencyKey, $callbackUrl);
    }

    /**
     * @return array{envelope_version: int, options: array<string, mixed>, idempotency_key?: string, callback_url?: string}
     */
    public function toArray(): array
    {
        $data = [
            'envelope_version' => $this->envelopeVersion,
            'options' => $this->options->toArray(),
        ];

        if ($this->idempotencyKey !== null) {
            $data['idempotency_key'] = $this->idempotencyKey;
        }

        if ($this->callbackUrl !== null) {
            $data['callback_url'] = $this->callbackUrl;
        }

        return $data;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $version = self::versionFrom($data);

        if (! array_key_exists('options', $data) || ! is_array($data['options'])) {
            throw new InvalidEnvelope('Removal request options are missing or malformed.');
        }

        return new self(
            options: RemovalOptions::fromArray($data['options']),
            idempotencyKey: self::optionalString($data, 'idempotency_key'),
            callbackUrl: self::optionalString($data, 'callback_url'),
            envelopeVersion: $version,
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidEnvelope('Removal request JSON is malformed.', previous: $exception);
        }

        if (! is_array($data)) {
            throw new InvalidEnvelope('Removal request JSON must decode to an object.');
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function versionFrom(array $data): int
    {
        return Protocol::versionFrom($data);
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
            throw new InvalidEnvelope(sprintf('Removal request %s is malformed.', $key));
        }

        return $data[$key];
    }
}
