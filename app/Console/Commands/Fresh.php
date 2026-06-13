<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Fresh extends Command
{
    protected $signature = 'fresh';

    protected $description = 'Reset and seed the database.';

    public function handle(): int
    {
        return $this->call('migrate:fresh', [
            '--seed' => true,
        ]);
    }
}
