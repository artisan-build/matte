<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Jobs;

use ArtisanBuild\BuiltForCloud\Hmac\HmacEnvelope;
use ArtisanBuild\BuiltForCloud\Hmac\HmacSigner;
use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteContracts\JobStatusEnvelope;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\CallbackDestination;
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
        public ?string $callbackDestination = null,
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

        $this->deliverCallback($matteJob->refresh());
    }

    private function deliverCallback(MatteJob $matteJob): void
    {
        if ($this->callbackDestination === null) {
            return;
        }

        try {
            $destination = CallbackDestination::resolve($this->callbackDestination);

            if ($destination === null) {
                return;
            }

            $body = JobStatusEnvelope::make(
                $matteJob->id,
                $matteJob->status,
                $matteJob->output_ref,
                $matteJob->error,
            )->toJson();
            $signature = app(HmacSigner::class)->signBound(
                $destination->scope,
                $body,
                'matte.removal.completed',
            );

            Http::timeout(max(1, (int) config('matte-server.callback.timeout', 5)))
                ->connectTimeout(max(1, (int) config('matte-server.callback.connect_timeout', 2)))
                ->withHeader(HmacEnvelope::HEADER, $signature)
                ->withBody($body, 'application/json')
                ->post($destination->url)
                ->throw();
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
                // Callback delivery and its observability are both best effort.
            }
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
