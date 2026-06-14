<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient;

use ArtisanBuild\MatteClient\Exceptions\MatteException;
use ArtisanBuild\MatteContracts\JobStatusEnvelope;
use ArtisanBuild\MatteContracts\RemovalOptions;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use SplFileInfo;

final readonly class MatteClient
{
    public function __construct(
        private ?string $url = null,
        private ?string $token = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function remove(mixed $image, array $options = [], ?string $callbackUrl = null): JobHandle
    {
        $payload = $this->postRemove($image, $options, $callbackUrl, sync: false);

        if ($payload->status() !== 202) {
            throw MatteException::unexpectedResponse($payload->status(), $payload->body());
        }

        $data = $payload->json();

        if (! is_array($data) || ! is_string($data['job_id'] ?? null)) {
            throw new MatteException('Matte server response is missing a job ID.');
        }

        return new JobHandle($this, $data['job_id']);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function removeSync(mixed $image, array $options = []): string
    {
        $payload = $this->postRemove($image, $options, callbackUrl: null, sync: true);

        if ($payload->status() !== 200) {
            throw MatteException::unexpectedResponse($payload->status(), $payload->body());
        }

        return $payload->body();
    }

    public function status(string $jobId): JobStatusEnvelope
    {
        $payload = Http::withToken($this->configuredToken())
            ->get($this->endpoint("/v1/jobs/{$jobId}"));

        if (! $payload->successful()) {
            throw MatteException::unexpectedResponse($payload->status(), $payload->body());
        }

        $data = $payload->json();

        if (! is_array($data)) {
            throw new MatteException('Matte server returned malformed job status JSON.');
        }

        return JobStatusEnvelope::fromArray($data);
    }

    public function result(string $jobId): string
    {
        $payload = Http::withToken($this->configuredToken())
            ->get($this->endpoint("/v1/jobs/{$jobId}/result"));

        if ($payload->status() !== 200) {
            throw MatteException::unexpectedResponse($payload->status(), $payload->body());
        }

        return $payload->body();
    }

    public function pollInterval(): int
    {
        return max(0, (int) config('matte.poll_interval', 2));
    }

    public function pollTimeout(): int
    {
        return max(1, (int) config('matte.poll_timeout', 120));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function postRemove(mixed $image, array $options, ?string $callbackUrl, bool $sync): Response
    {
        $normalizedImage = $this->normalizeImage($image);
        $fields = $this->options($options)->toArray();

        if ($callbackUrl !== null) {
            $fields['callback_url'] = $callbackUrl;
        }

        $url = $this->endpoint('/v1/remove').($sync ? '?sync=1' : '');

        return Http::withToken($this->configuredToken())
            ->attach('image', $normalizedImage['contents'], $normalizedImage['filename'])
            ->post($url, $fields);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function options(array $options): RemovalOptions
    {
        return RemovalOptions::fromArray(array_merge([
            'mode' => config('matte.default_mode', 'grabcut'),
            'preset' => config('matte.default_preset', 'balanced'),
        ], $options));
    }

    /**
     * @return array{contents: string, filename: string}
     */
    private function normalizeImage(mixed $image): array
    {
        if ($image instanceof UploadedFile) {
            return [
                'contents' => (string) file_get_contents($image->getRealPath() ?: $image->getPathname()),
                'filename' => $image->getClientOriginalName(),
            ];
        }

        if ($image instanceof SplFileInfo) {
            return [
                'contents' => (string) file_get_contents($image->getPathname()),
                'filename' => $image->getFilename(),
            ];
        }

        if (is_string($image) && is_file($image)) {
            return [
                'contents' => (string) file_get_contents($image),
                'filename' => basename($image),
            ];
        }

        if (is_string($image)) {
            return [
                'contents' => $image,
                'filename' => 'image.png',
            ];
        }

        throw new MatteException('Matte image must be a file path, raw bytes, SplFileInfo, or UploadedFile.');
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->configuredUrl(), '/').$path;
    }

    private function configuredUrl(): string
    {
        if ($this->url === null || $this->url === '') {
            throw new MatteException('Matte server URL is not configured.');
        }

        return $this->url;
    }

    private function configuredToken(): string
    {
        if ($this->token === null || $this->token === '') {
            throw new MatteException('Matte API token is not configured.');
        }

        return $this->token;
    }
}
