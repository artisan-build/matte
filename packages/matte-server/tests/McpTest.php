<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\BuiltForCloud\Http\Middleware\AuthenticateMcp;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteServer\MatteJob;
use ArtisanBuild\MatteServer\Mcp\MatteMcpServer;
use ArtisanBuild\MatteServer\Mcp\Support\McpResponse;
use ArtisanBuild\MatteServer\Mcp\Tools\JobStatusTool;
use ArtisanBuild\MatteServer\Mcp\Tools\RecentJobsTool;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request as McpRequest;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\Version4\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'built-for-cloud.console.issuer' => 'https://scalpels.test',
        'built-for-cloud.console.audience' => 'https://matte-installation.test',
    ]);
});

function matteMcpSigningKey(): AsymmetricSecretKey
{
    if (app()->bound('matte.testing.mcp-signing-key')) {
        return app('matte.testing.mcp-signing-key');
    }

    foreach (range(1, 16) as $ignored) {
        $secret = AsymmetricSecretKey::generate(new Version4);

        if (strlen($secret->raw()) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $keyring = new ConsoleKeyring;
            $keyring->activate($keyring->add('matte-test-key', $secret->getPublicKey()->toHexString())->key_id);
            app()->instance('matte.testing.mcp-signing-key', $secret);

            return $secret;
        }
    }

    throw new RuntimeException('Could not generate a valid MCP test signing key.');
}

function matteMcpAssertion(
    string $subject = 'operator_42',
    string $audience = 'https://matte-installation.test',
): string {
    $now = CarbonImmutable::now();

    return (new Builder)
        ->setVersion(new Version4)
        ->setPurpose(Purpose::public())
        ->setKey(matteMcpSigningKey())
        ->setClaims([
            'iss' => 'https://scalpels.test',
            'sub' => $subject,
            'aud' => $audience,
            'iat' => $now->toAtomString(),
            'nbf' => $now->toAtomString(),
            'exp' => $now->addSeconds(90)->toAtomString(),
            'jti' => 'matte_'.bin2hex(random_bytes(8)),
            'display_name' => 'Matte Operator',
            'role' => 'member',
            'purpose' => 'mcp',
        ])
        ->setFooterArray(['kid' => 'matte-test-key'])
        ->toString();
}

/** @param array<string, mixed> $arguments */
function matteToolResult(string $tool, array $arguments): array
{
    $instance = app($tool);
    $response = $instance->handle(new McpRequest($arguments));

    return $response->getStructuredContent() ?? [];
}

/** @param array<string, mixed> $payload */
function matteMcpPost(array $payload, ?string $assertion = null): TestResponse
{
    return test()->postJson('/mcp', $payload, [
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.($assertion ?? matteMcpAssertion()),
    ]);
}

/** @return array<string, mixed> */
function matteCallPayload(string $name, array $arguments, string $id = 'matte-test'): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ];
}

it('exposes exactly the two read-only content tools with closed schemas', function (): void {
    $discovered = McpDelegatedTools::discover(MatteMcpServer::class);
    $tools = [app(JobStatusTool::class), app(RecentJobsTool::class)];

    expect($discovered['tools'])->toEqualCanonicalizing([
        JobStatusTool::class,
        RecentJobsTool::class,
    ]);

    foreach ($tools as $tool) {
        $serialized = $tool->toArray();

        expect($serialized['_meta'][ToolEffect::META_KEY])->toBe(Effect::Read->value)
            ->and($serialized['_meta'][ToolClassification::META_KEY])->toBe(Classification::Content->value)
            ->and($serialized['inputSchema']['additionalProperties'])->toBeFalse();
    }

    expect(array_map(static fn ($tool): string => $tool->name(), $tools))->toBe([
        'job_status',
        'recent_jobs',
    ])->not->toContain(
        'upload_image',
        'create_job',
        'job_result',
        'remove_background',
        'delete_job',
    );

    McpDelegatedTools::assertConforms(MatteMcpServer::class);
});

it('guards the product MCP endpoint with the exact read door and refuses a cross-scope delegated actor', function (): void {
    $route = Route::getRoutes()->match(HttpRequest::create('/mcp', 'POST'));

    expect($route->gatherMiddleware())->toContain('bfc.mcp:product,read')
        ->and(config('built-for-cloud.mcp.path'))->toBe('/mcp')
        ->and(config('built-for-cloud.mcp.delegated'))->toBeTrue()
        ->and(config('built-for-cloud.mcp.write_path'))->toBeNull()
        ->and(config('built-for-cloud.mcp.destructive_path'))->toBeNull();

    $job = MatteJob::factory()->create();
    $list = matteMcpPost([
        'jsonrpc' => '2.0',
        'id' => 'list',
        'method' => 'tools/list',
        'params' => [],
    ]);

    $list->assertOk();
    expect(collect($list->json('result.tools'))->pluck('name')->all())->toBe([
        'job_status',
        'recent_jobs',
    ]);

    foreach (['same-installation-member-a', 'same-installation-member-b'] as $member) {
        matteMcpPost(matteCallPayload('recent_jobs', []), matteMcpAssertion($member))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.jobs.0.id', $job->id);
    }

    matteMcpPost(
        matteCallPayload('recent_jobs', []),
        matteMcpAssertion('cross-scope-member', 'https://another-installation.test'),
    )->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);

    $errorMarker = 'oversized-private-error';
    $oversized = MatteJob::factory()->failed()->create([
        'error' => str_repeat($errorMarker, 50_000),
        'input_ref' => 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\nprivate-input"),
        'output_ref' => 'https://storage.example.test/private-output.png',
    ]);
    $maximumId = str_repeat('x', AuthenticateMcp::MAX_JSON_RPC_ID_BYTES - 2);

    expect(strlen(json_encode($maximumId, JSON_THROW_ON_ERROR)))
        ->toBe(AuthenticateMcp::MAX_JSON_RPC_ID_BYTES);

    $bounded = matteMcpPost(matteCallPayload('recent_jobs', [], $maximumId));

    $bounded->assertOk()
        ->assertJsonPath('id', $maximumId)
        ->assertJsonPath('result.structuredContent.error', 'job_row_exceeds_relay_limit');

    expect(strlen((string) $bounded->getContent()))->toBeLessThan(McpResponse::RELAY_BODY_CAP_BYTES)
        ->and((string) $bounded->getContent())->not->toContain(
            $errorMarker,
            'data:image/png',
            'storage.example.test',
            'input_ref',
            'output_ref',
        );
});

it('rejects numeric unknown and odd-shaped tool input at runtime', function (string $tool, array $arguments): void {
    expect(fn (): array => matteToolResult($tool, $arguments))->toThrow(ValidationException::class);
})->with([
    'numeric job id' => [JobStatusTool::class, ['job_id' => 42]],
    'unknown job status key' => [JobStatusTool::class, ['job_id' => fake()->uuid(), 'extra' => true]],
    'array status' => [RecentJobsTool::class, ['status' => ['queued']]],
    'numeric cursor' => [RecentJobsTool::class, ['cursor' => 42]],
    'natural-language timestamp' => [RecentJobsTool::class, ['created_from' => 'yesterday']],
    'array limit' => [RecentJobsTool::class, ['limit' => [25]]],
    'unknown recent jobs key' => [RecentJobsTool::class, ['owner_id' => fake()->uuid()]],
]);

it('returns bounded job metadata while selecting and exposing no storage references', function (): void {
    $binaryMarker = base64_encode("\x89PNG\r\n\x1a\nprivate-image");
    $inputMarker = 'data:image/png;base64,'.$binaryMarker;
    $outputMarker = 'https://storage.example.test/outputs/secret-result.png';
    $job = MatteJob::factory()->failed()->create([
        'input_ref' => $inputMarker,
        'output_ref' => $outputMarker,
        'error' => 'conversion failed',
    ]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'matte_jobs')) {
            $queries[] = $query->sql;
        }
    });

    $result = matteToolResult(JobStatusTool::class, ['job_id' => $job->id]);
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);

    expect($result)->toHaveKey('job.id', $job->id)
        ->and($result)->toHaveKey('job.status', JobStatus::Failed->value)
        ->and($result)->toHaveKey('job.error', 'conversion failed')
        ->and($encoded)->not->toContain($inputMarker, $outputMarker, $binaryMarker, 'input_ref', 'output_ref')
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('"id"', '"status"', '"error"', '"created_at"', '"updated_at"')
        ->and($queries[0])->not->toContain('input_ref', 'output_ref');
});

it('binds opaque cursors to filters and rejects tampering', function (): void {
    MatteJob::factory()->count(3)->create(['status' => JobStatus::Queued]);
    MatteJob::factory()->count(3)->done()->create();

    $first = matteToolResult(RecentJobsTool::class, [
        'status' => JobStatus::Queued->value,
        'limit' => 2,
    ]);
    $cursor = $first['next_cursor'];

    expect($cursor)->toBeString()->not->toBeEmpty();

    expect(fn (): array => matteToolResult(RecentJobsTool::class, [
        'status' => JobStatus::Done->value,
        'limit' => 2,
        'cursor' => $cursor,
    ]))->toThrow(ValidationException::class);

    $offset = intdiv(strlen($cursor), 2);
    $tampered = substr_replace($cursor, $cursor[$offset] === 'a' ? 'b' : 'a', $offset, 1);

    expect(fn (): array => matteToolResult(RecentJobsTool::class, [
        'status' => JobStatus::Queued->value,
        'limit' => 2,
        'cursor' => $tampered,
    ]))->toThrow(ValidationException::class);
});

it('paginates more than one hundred same-time jobs without duplicates or omissions', function (): void {
    $createdAt = CarbonImmutable::parse('2026-09-30T12:00:00Z');
    MatteJob::factory()->count(205)->create([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $expected = MatteJob::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
    $actual = [];
    $cursor = null;

    do {
        $arguments = ['limit' => 100];

        if ($cursor !== null) {
            $arguments['cursor'] = $cursor;
        }

        $page = matteToolResult(RecentJobsTool::class, $arguments);
        $actual = [...$actual, ...array_column($page['jobs'], 'id')];
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);

    expect($actual)->toBe($expected)
        ->and($actual)->toHaveCount(205)
        ->and(array_values(array_unique($actual)))->toHaveCount(205);
});
