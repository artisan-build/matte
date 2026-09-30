<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteServer\MatteJob;
use ArtisanBuild\MatteServer\Mcp\Support\McpInput;
use ArtisanBuild\MatteServer\Mcp\Support\McpResponse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('job_status')]
#[Description('Return bounded status metadata for one Matte background-removal job. Storage references and image data are never returned.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class JobStatusTool extends Tool
{
    use AdvertisesToolClassification, AdvertisesToolEffect {
        AdvertisesToolClassification::toArray insteadof AdvertisesToolEffect;
        AdvertisesToolClassification::toArray as private advertisedArray;
    }
    use RespectsEffectCeiling;

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        $tool = $this->advertisedArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'job_id' => $schema->string()->format('uuid')->description('Matte job UUID.')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        McpInput::closed($request, ['job_id']);
        $jobId = McpInput::requiredString($request, 'job_id', 36);

        if (! Str::isUuid($jobId)) {
            McpInput::fail('job_id', 'The job_id argument is invalid.');
        }

        $job = MatteJob::query()
            ->select(['id', 'status', 'created_at', 'updated_at'])
            ->find($jobId);

        if (! $job instanceof MatteJob) {
            return McpResponse::structured($this, ['error' => 'job_not_found']);
        }

        $response = ['job' => self::serialize($job)];

        return McpResponse::fits($this, $response)
            ? McpResponse::structured($this, $response)
            : McpResponse::structured($this, ['error' => 'job_row_exceeds_relay_limit']);
    }

    /** @return array{id: string, status: string, error: string|null, created_at: string, updated_at: string} */
    public static function serialize(MatteJob $job): array
    {
        return [
            'id' => $job->id,
            'status' => $job->status->value,
            'error' => $job->status === JobStatus::Failed ? 'job_failed' : null,
            'created_at' => $job->created_at->toISOString(),
            'updated_at' => $job->updated_at->toISOString(),
        ];
    }
}
