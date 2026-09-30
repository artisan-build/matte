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
use ArtisanBuild\MatteServer\Mcp\Support\OpaqueCursor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('recent_jobs')]
#[Description('List recent Matte jobs newest-first with bounded status and creation-time filters plus opaque cursor pagination. Storage references and image data are never returned.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class RecentJobsTool extends Tool
{
    use AdvertisesToolClassification, AdvertisesToolEffect {
        AdvertisesToolClassification::toArray insteadof AdvertisesToolEffect;
        AdvertisesToolClassification::toArray as private advertisedArray;
    }
    use RespectsEffectCeiling;

    private const int DEFAULT_LIMIT = 25;

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
            'status' => $schema->string()->enum(JobStatus::class),
            'created_from' => $schema->string()->format('date-time')->max(64)->description('Inclusive ISO-8601 creation timestamp.'),
            'created_to' => $schema->string()->format('date-time')->max(64)->description('Inclusive ISO-8601 creation timestamp.'),
            'cursor' => $schema->string()->min(1)->max(2048),
            'limit' => $schema->integer()->min(1)->max(100)->default(self::DEFAULT_LIMIT),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        McpInput::closed($request, ['status', 'created_from', 'created_to', 'cursor', 'limit']);
        $limit = McpInput::integer($request, 'limit', self::DEFAULT_LIMIT, 1, 100);
        $filters = $this->filters($request);
        $scope = hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
        $cursor = OpaqueCursor::decode(McpInput::optionalString($request, 'cursor', 2048), 'recent_jobs', $scope);
        $query = MatteJob::query()->select(['id', 'status', 'created_at', 'updated_at']);

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['created_from'] !== null) {
            $query->where('created_at', '>=', $filters['created_from']);
        }

        if ($filters['created_to'] !== null) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        if ($cursor !== []) {
            $createdAt = $cursor['created_at'] ?? null;
            $id = $cursor['id'] ?? null;

            if (! is_string($createdAt) || ! is_string($id)) {
                McpInput::fail('cursor', 'The cursor is invalid.');
            }

            $query->where(function (Builder $page) use ($createdAt, $id): void {
                $page->where('created_at', '<', $createdAt)
                    ->orWhere(function (Builder $sameTime) use ($createdAt, $id): void {
                        $sameTime->where('created_at', $createdAt)->where('id', '<', $id);
                    });
            });
        }

        $rows = $query->latest('created_at')->orderByDesc('id')->limit($limit + 1)->get()->values();
        $jobs = [];
        $page = null;

        foreach ($rows->take($limit) as $index => $job) {
            $hasMore = $index + 1 < $rows->count();
            $candidate = [
                'jobs' => [...$jobs, JobStatusTool::serialize($job)],
                'next_cursor' => $hasMore ? $this->cursor($scope, $job) : null,
            ];

            if (! McpResponse::fits($this, $candidate)) {
                if ($page === null) {
                    return McpResponse::structured($this, ['error' => 'job_row_exceeds_relay_limit']);
                }

                return McpResponse::structured($this, $page);
            }

            $jobs = $candidate['jobs'];
            $page = $candidate;

            if (! $hasMore || count($jobs) === $limit) {
                return McpResponse::structured($this, $page);
            }
        }

        return McpResponse::structured($this, ['jobs' => [], 'next_cursor' => null]);
    }

    /** @return array{status: string|null, created_from: string|null, created_to: string|null} */
    private function filters(Request $request): array
    {
        $filters = [
            'status' => McpInput::optionalEnum($request, 'status', array_column(JobStatus::cases(), 'value')),
            'created_from' => $this->timestamp($request, 'created_from'),
            'created_to' => $this->timestamp($request, 'created_to'),
        ];

        if ($filters['created_from'] !== null && $filters['created_to'] !== null
            && $filters['created_from'] > $filters['created_to']) {
            McpInput::fail('created_from', 'The created_from argument must not be after created_to.');
        }

        return $filters;
    }

    private function timestamp(Request $request, string $key): ?string
    {
        $value = McpInput::optionalString($request, $key, 64);

        if ($value === null) {
            return null;
        }

        if (preg_match(
            '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})T(?<time>\d{2}:\d{2}:\d{2})(?<fraction>\.\d{1,6})?(?<offset>Z|[+-](?<offset_hour>\d{2}):(?<offset_minute>\d{2}))$/D',
            $value,
            $parts,
        ) !== 1) {
            McpInput::fail($key, "The {$key} argument is invalid.");
        }

        [$hour, $minute, $second] = array_map('intval', explode(':', $parts['time']));

        if (! checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])
            || $hour > 23 || $minute > 59 || $second > 59
            || ($parts['offset'] !== 'Z'
                && ((int) $parts['offset_hour'] > 23 || (int) $parts['offset_minute'] > 59))) {
            McpInput::fail($key, "The {$key} argument is invalid.");
        }

        try {
            $format = $parts['fraction'] === '' ? '!Y-m-d\TH:i:sP' : '!Y-m-d\TH:i:s.uP';
            $timestamp = CarbonImmutable::createFromFormat($format, $value);
        } catch (Throwable) {
            McpInput::fail($key, "The {$key} argument is invalid.");
        }

        $timestamp = $timestamp->utc();
        $format = $timestamp->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u';

        return $timestamp->format($format);
    }

    private function cursor(string $scope, MatteJob $job): string
    {
        return OpaqueCursor::encode([
            'kind' => 'recent_jobs',
            'scope' => $scope,
            'created_at' => (string) $job->getRawOriginal('created_at'),
            'id' => $job->id,
        ]);
    }
}
