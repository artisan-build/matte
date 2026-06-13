<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Jobs;

use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\Converter;
use ArtisanBuild\MatteServer\Exceptions\ConversionFailed;
use ArtisanBuild\MatteServer\MatteJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class RemoveBackgroundJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $matteJobId,
        public RemovalOptions $options,
        public string $diskName,
        public string $inputRef,
        public string $outputKey,
        public ?string $callbackUrl = null,
    ) {
        $this->connection = config('matte-server.queue');
    }

    public function handle(Converter $converter): void
    {
        $matteJob = MatteJob::query()->findOrFail($this->matteJobId);
        $matteJob->forceFill(['status' => JobStatus::Processing])->save();

        $inputTemp = $this->temporaryPath('matte-input-', '.png');
        $outputTemp = $this->temporaryPath('matte-output-', '.png');

        try {
            file_put_contents($inputTemp, Storage::disk($this->diskName)->get($this->inputRef));

            $converter->convert($inputTemp, $outputTemp, $this->options);

            Storage::disk($this->diskName)->put($this->outputKey, file_get_contents($outputTemp));

            $matteJob->forceFill([
                'output_ref' => $this->outputKey,
                'status' => JobStatus::Done,
                'error' => null,
            ])->save();
        } catch (ConversionFailed $exception) {
            $matteJob->forceFill([
                'status' => JobStatus::Failed,
                'error' => $exception->getMessage(),
            ])->save();
        } finally {
            $this->notifyCallback($matteJob->refresh());
            @unlink($inputTemp);
            @unlink($outputTemp);
        }
    }

    private function temporaryPath(string $prefix, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new ConversionFailed('Unable to create temporary file.');
        }

        $suffixedPath = $path.$suffix;
        rename($path, $suffixedPath);

        return $suffixedPath;
    }

    private function notifyCallback(MatteJob $matteJob): void
    {
        if ($this->callbackUrl === null) {
            return;
        }

        try {
            Http::timeout(5)->post($this->callbackUrl, [
                'job_id' => $matteJob->getKey(),
                'status' => $matteJob->status->value,
                'output_ref' => $matteJob->output_ref,
                'error' => $matteJob->error,
            ]);
        } catch (Throwable) {
            // Best-effort callback only; conversion state is already persisted.
        }
    }
}
