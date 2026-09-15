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
use Illuminate\Support\Facades\Storage;

final class RemoveBackgroundJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $matteJobId,
        public RemovalOptions $options,
        public string $diskName,
        public string $inputRef,
        public string $outputKey,
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
}
