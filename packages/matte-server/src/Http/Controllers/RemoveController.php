<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Http\Controllers;

use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialOwnership;
use ArtisanBuild\BuiltForCloud\CredentialUsageRecorder;
use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\MatteContracts\Exceptions\InvalidEnvelope;
use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteContracts\JobStatusEnvelope;
use ArtisanBuild\MatteContracts\Protocol;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\Converter;
use ArtisanBuild\MatteServer\Exceptions\ConversionFailed;
use ArtisanBuild\MatteServer\Jobs\RemoveBackgroundJob;
use ArtisanBuild\MatteServer\MatteJob;
use ArtisanBuild\MatteServer\OutputKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class RemoveController extends Controller
{
    public function __construct(
        private readonly BearerAuthenticator $authenticator,
        private readonly AppPurposeRegistry $purposes,
        private readonly CredentialUsageRecorder $usage,
    ) {}

    public function store(Request $request, Converter $converter): JsonResponse|Response
    {
        if (! $this->admit($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        try {
            $this->assertSupportedEnvelopeVersion($request);
            $this->validateImage($request);
            $options = $this->removalOptions($request);
            $bytes = $this->uploadedBytes($request);
        } catch (InvalidEnvelope $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $diskName = (string) config('matte-server.disk', 'local');
        $inputRef = 'originals/'.hash('sha256', $bytes).'.bin';
        $outputKey = OutputKey::for($bytes, $options);

        Storage::disk($diskName)->put($inputRef, $bytes);

        $matteJob = MatteJob::query()->create([
            'input_ref' => $inputRef,
            'mode' => $options->mode->value,
            'preset' => $options->preset->value,
            'model' => $options->model,
            'status' => JobStatus::Queued,
        ]);

        if ($request->boolean('sync')) {
            return $this->convertSynchronously($converter, $matteJob, $options, $diskName, $bytes, $outputKey);
        }

        RemoveBackgroundJob::dispatch(
            $matteJob->id,
            $options,
            $diskName,
            $inputRef,
            $outputKey,
        );

        return response()->json(JobStatusEnvelope::make($matteJob->id, JobStatus::Queued)->toArray(), 202);
    }

    public function show(Request $request, string $jobId): JsonResponse
    {
        if (! $this->admit($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $matteJob = MatteJob::query()->find($jobId);

        if ($matteJob === null) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        return response()->json(JobStatusEnvelope::make(
            $matteJob->id,
            $matteJob->status,
            $matteJob->output_ref,
            $matteJob->error,
        )->toArray());
    }

    public function result(Request $request, string $jobId): JsonResponse|Response
    {
        if (! $this->admit($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $matteJob = MatteJob::query()->find($jobId);

        if ($matteJob === null) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if ($matteJob->status !== JobStatus::Done) {
            return response()->json([
                'message' => 'Job is not complete.',
                'status' => $matteJob->status->value,
            ], 409);
        }

        $disk = Storage::disk((string) config('matte-server.disk', 'local'));

        if ($matteJob->output_ref === null || ! $disk->exists($matteJob->output_ref)) {
            return response()->json(['message' => 'Result not available.'], 404);
        }

        return response($disk->get($matteJob->output_ref), 200)
            ->header('Content-Type', 'image/png');
    }

    private function assertSupportedEnvelopeVersion(Request $request): void
    {
        $version = $request->input('envelope_version', Protocol::ENVELOPE_VERSION);

        if (! is_numeric($version)) {
            throw new InvalidEnvelope('Envelope version is missing or malformed.');
        }

        if (! Protocol::isSupported((int) $version)) {
            throw new InvalidEnvelope('The client is ahead of this Matte instance - upgrade it.');
        }
    }

    private function validateImage(Request $request): void
    {
        $validator = Validator::make($request->all(), [
            'callback_url' => ['prohibited'],
            'image' => ['required', 'image'],
        ]);

        if ($validator->fails()) {
            throw new InvalidEnvelope($validator->errors()->first() ?: 'The request is invalid.');
        }
    }

    private function admit(Request $request): bool
    {
        $credential = $this->authenticator->credential($request);

        if ($credential === null || $credential->purpose !== $this->purposes->purpose('matte.remove')) {
            return false;
        }

        $principalIsAllowed = match ($credential->ownership()) {
            CredentialOwnership::Installation => $credential->subject_type === SubjectType::Installation,
            CredentialOwnership::Account => $this->accountCanUseProduct($credential),
        };

        return $principalIsAllowed && $this->usage->recordUsage($credential);
    }

    private function accountCanUseProduct(Credential $credential): bool
    {
        $userId = filter_var(
            $credential->user_id,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($userId)) {
            return false;
        }

        $user = User::query()->find($userId);

        return $user instanceof User
            && hash_equals((string) $user->getAuthIdentifier(), (string) $credential->user_id)
            && DomainIdentityContext::forUser($user, InstallationAuthority::current())->canUseProduct();
    }

    private function removalOptions(Request $request): RemovalOptions
    {
        return RemovalOptions::fromArray([
            'mode' => $request->input('mode', config('matte-server.default_mode', 'grabcut')),
            'preset' => $request->input('preset', 'balanced'),
            'model' => $this->nullableStringInput($request, 'model'),
            'edge_mode' => $this->nullableStringInput($request, 'edge_mode'),
            'iterations' => $this->nullableIntegerInput($request, 'iterations'),
            'margin' => $this->nullableIntegerInput($request, 'margin'),
        ]);
    }

    private function uploadedBytes(Request $request): string
    {
        $file = $request->file('image');
        $path = $file?->getRealPath();
        $bytes = $path === false || $path === null ? false : file_get_contents($path);

        if (! is_string($bytes)) {
            throw new InvalidEnvelope('Unable to read uploaded image.');
        }

        return $bytes;
    }

    private function nullableStringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableIntegerInput(Request $request, string $key): ?int
    {
        $value = $request->input($key);

        if ($value === null || $value === '') {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if ($integer === false) {
            throw new InvalidEnvelope(sprintf('Removal option %s is malformed.', $key));
        }

        return $integer;
    }

    private function convertSynchronously(
        Converter $converter,
        MatteJob $matteJob,
        RemovalOptions $options,
        string $diskName,
        string $bytes,
        string $outputKey,
    ): JsonResponse|Response {
        if (! $converter->dependenciesAvailable()) {
            return response()->json([
                'message' => 'Matte binary dependencies are not available.',
                'remediation' => 'macOS: run `brew install opencv onnxruntime`',
            ], 503);
        }

        $inputTemp = $this->temporaryPath('matte-http-input-', '.png');
        $outputTemp = $this->temporaryPath('matte-http-output-', '.png');

        try {
            file_put_contents($inputTemp, $bytes);
            $converter->convert($inputTemp, $outputTemp, $options);
            $pngBytes = file_get_contents($outputTemp);

            if (! is_string($pngBytes)) {
                throw new ConversionFailed('Conversion completed without readable output.');
            }

            Storage::disk($diskName)->put($outputKey, $pngBytes);

            $matteJob->forceFill([
                'output_ref' => $outputKey,
                'status' => JobStatus::Done,
                'error' => null,
            ])->save();

            return response($pngBytes, 200)->header('Content-Type', 'image/png');
        } catch (Throwable $exception) {
            $matteJob->forceFill([
                'status' => JobStatus::Failed,
                'error' => $exception->getMessage(),
            ])->save();

            return response()->json(['message' => $exception->getMessage()], 500);
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
