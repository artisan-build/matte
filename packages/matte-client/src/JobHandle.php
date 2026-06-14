<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient;

use ArtisanBuild\MatteClient\Exceptions\MatteException;
use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteContracts\JobStatusEnvelope;

final class JobHandle
{
    private ?JobStatusEnvelope $terminalStatus = null;

    public function __construct(
        private readonly MatteClient $client,
        private readonly string $id,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function status(): JobStatusEnvelope
    {
        return $this->client->status($this->id);
    }

    public function wait(?int $timeout = null): JobStatusEnvelope
    {
        if ($this->terminalStatus !== null) {
            return $this->terminalStatus;
        }

        $deadline = microtime(true) + ($timeout ?? $this->client->pollTimeout());

        do {
            $status = $this->status();

            if ($status->status === JobStatus::Done) {
                return $this->terminalStatus = $status;
            }

            if ($status->status === JobStatus::Failed) {
                throw new MatteException($status->error ?? 'Matte removal failed.');
            }

            $interval = $this->client->pollInterval();

            if ($interval > 0) {
                sleep($interval);
            }
        } while (microtime(true) < $deadline);

        throw new MatteException(sprintf('Timed out waiting for Matte job [%s].', $this->id));
    }

    public function result(): string
    {
        $this->wait();

        return $this->client->result($this->id);
    }
}
