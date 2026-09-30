<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Mcp\Support;

use ArtisanBuild\BuiltForCloud\Http\Middleware\AuthenticateMcp;
use ArtisanBuild\MatteServer\Mcp\MatteMcpServer;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcResponse;

final class McpResponse
{
    public const int RELAY_BODY_CAP_BYTES = 1_048_576;

    /** @param array<string, mixed> $structured */
    public static function structured(Tool $tool, array $structured): ResponseFactory
    {
        if (! self::fits($tool, $structured)) {
            return Response::structured(['error' => 'response_exceeds_relay_limit']);
        }

        return Response::structured($structured);
    }

    /** @param array<string, mixed> $structured */
    public static function fits(Tool $tool, array $structured): bool
    {
        $factory = Response::structured($structured);
        $result = $factory->mergeStructuredContent($factory->mergeMeta([
            'content' => $factory->responses()
                ->map(fn (Response $response): array => $response->content()->toTool($tool))
                ->all(),
            'isError' => $factory->responses()->contains(fn (Response $response): bool => $response->isError()),
        ]));
        $result['_meta'][MetaKey::SERVER_INFO->value] = [
            'name' => MatteMcpServer::NAME,
            'version' => MatteMcpServer::VERSION,
        ];

        $body = JsonRpcResponse::result(self::maximumRequestId(), [
            'resultType' => 'complete',
            ...$result,
        ])->toJson();

        return strlen($body) < self::RELAY_BODY_CAP_BYTES;
    }

    private static function maximumRequestId(): string
    {
        return str_repeat('x', AuthenticateMcp::MAX_JSON_RPC_ID_BYTES - 2);
    }
}
