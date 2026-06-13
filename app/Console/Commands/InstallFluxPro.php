<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class InstallFluxPro extends Command
{
    protected $signature = 'flux:pro';

    protected $description = 'Install Flux Pro using globally configured Composer credentials.';

    public function handle(): int
    {
        if (! $this->confirm('This command assumes that you have saved your Flux Pro credentials globally. If so, continue. If not, run flux:activate instead.')) {
            return self::SUCCESS;
        }

        $this->components->info('Adding the Flux Pro Composer repository...');

        if (! $this->runComposer(['config', 'repositories.flux-pro', 'composer', 'https://composer.fluxui.dev'])) {
            return self::FAILURE;
        }

        $this->components->info('Requiring Flux Pro...');

        if (! $this->runComposer(['require', 'livewire/flux-pro'])) {
            return self::FAILURE;
        }

        $this->components->info('Flux Pro installed.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runComposer(array $arguments): bool
    {
        $process = new Process(['composer', ...$arguments], base_path(), timeout: 300);
        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            $this->error('Composer command failed.');

            return false;
        }

        return true;
    }
}
