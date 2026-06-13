<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteContracts;

use ArtisanBuild\MatteContracts\Exceptions\InvalidEnvelope;
use JsonException;

final readonly class JobStatusEnvelope
{
    public function __construct(
        public string $jobId,
        public JobStatus $status,
        public ?string $outputRef = null,
        public ?string $error = null,
        public int $envelopeVersion = Protocol::ENVELOPE_VERSION,
    ) {}

    public static function make(
        string $jobId,
        JobStatus $status,
        ?string $outputRef = null,
        ?string $error = null,
    ): self {
        return new self($jobId, $status, $outputRef, $error);
    }

    /**
     * @return array{envelope_version: int, job_id: string, status: string, output_ref?: string, error?: string}
     */
    public function toArray(): array
    {
        $data = [
            'envelope_version' => $this->envelopeVersion,
            'job_id' => $this->jobId,
            'status' => $this->status->value,
        ];

        if ($this->outputRef !== null) {
            $data['output_ref'] = $this->outputRef;
        }

        if ($this->error !== null) {
            $data['error'] = $this->error;
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

        if (! array_key_exists('job_id', $data) || ! is_string($data['job_id'])) {
            throw new InvalidEnvelope('Job status envelope job ID is missing or malformed.');
        }

        if (! array_key_exists('status', $data) || ! is_string($data['status']) || ($status = JobStatus::tryFrom($data['status'])) === null) {
            throw new InvalidEnvelope('Job status envelope status is missing or malformed.');
        }

        return new self(
            jobId: $data['job_id'],
            status: $status,
            outputRef: self::optionalString($data, 'output_ref'),
            error: self::optionalString($data, 'error'),
            envelopeVersion: $version,
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidEnvelope('Job status envelope JSON is malformed.', previous: $exception);
        }

        if (! is_array($data)) {
            throw new InvalidEnvelope('Job status envelope JSON must decode to an object.');
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
            throw new InvalidEnvelope(sprintf('Job status envelope %s is malformed.', $key));
        }

        return $data[$key];
    }
}
