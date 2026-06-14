<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient\Jobs;

use ArtisanBuild\MatteClient\Events\MatteRemovalCompleted;
use ArtisanBuild\MatteClient\JobHandle;
use ArtisanBuild\MatteClient\MatteClient;
use ArtisanBuild\MatteContracts\JobStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AwaitRemovalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $jobId) {}

    public function handle(MatteClient $client): void
    {
        try {
            $status = (new JobHandle($client, $this->jobId))->wait();
        } catch (Throwable $throwable) {
            Event::dispatch(new MatteRemovalCompleted($this->jobId, JobStatus::Failed, error: $throwable->getMessage()));

            return;
        }

        $path = null;

        if ($status->status === JobStatus::Done) {
            $bytes = $client->result($this->jobId);

            if (($disk = config('matte.store_disk')) !== null && $disk !== '') {
                $path = "matte/{$this->jobId}.png";
                Storage::disk($disk)->put($path, $bytes);
            }
        }

        Event::dispatch(new MatteRemovalCompleted($this->jobId, $status->status, $path, $status->error));
    }
}
