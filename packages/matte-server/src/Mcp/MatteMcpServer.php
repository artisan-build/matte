<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Mcp;

use ArtisanBuild\MatteServer\Mcp\Tools\JobStatusTool;
use ArtisanBuild\MatteServer\Mcp\Tools\RecentJobsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name(self::NAME)]
#[Version(self::VERSION)]
#[Instructions('Inspect bounded Matte background-removal job status metadata. Image data and storage references are never available through this server.')]
final class MatteMcpServer extends Server
{
    public const string NAME = 'Matte';

    public const string VERSION = '1.0.0';

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        JobStatusTool::class,
        RecentJobsTool::class,
    ];
}
