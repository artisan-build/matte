<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Commands;

use Illuminate\Console\Command;

final class IssueTokenCommand extends Command
{
    protected $signature = 'matte:issue-token {id}';

    protected $description = 'Issue a Matte API bearer token and MATTE_TOKENS hash entry.';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $this->line('Token: '.$token);
        $this->line('MATTE_TOKENS entry:');
        $this->line($id.'='.$hash);

        return self::SUCCESS;
    }
}
