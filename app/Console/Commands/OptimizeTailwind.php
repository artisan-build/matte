<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class OptimizeTailwind extends Command
{
    protected $signature = 'tailwind:optimize
        {--tailwind-version=v4.3.0 : Tailwind CSS standalone CLI release version}
        {--force-download : Download the Tailwind CSS binary even when one is already cached}
        {--input=resources/css/tailwind.css : Source CSS file to compile}
        {--output=public/build/assets/app.css : Compiled CSS output path}
        {--no-minify : Skip minifying the compiled CSS}';

    protected $description = 'Optionally optimize the checked-in Tailwind CSS without Node, npm, or Vite.';

    public function handle(): int
    {
        $version = $this->normalizeVersion((string) $this->option('tailwind-version'));
        $binary = $this->binaryPath($version);

        if ($this->option('force-download') || ! File::exists($binary)) {
            $this->downloadBinary($version, $binary);
        }

        $input = base_path((string) $this->option('input'));
        $output = base_path((string) $this->option('output'));

        if (! File::exists($input)) {
            $this->error("Tailwind input file does not exist: {$input}");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($output));

        $command = [$binary, '-i', $input, '-o', $output];

        if (! $this->option('no-minify')) {
            $command[] = '--minify';
        }

        $this->components->info('Optimizing Tailwind CSS...');

        $process = new Process($command, base_path(), timeout: 120);
        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            $this->error('Tailwind CSS optimization failed.');

            return self::FAILURE;
        }

        $this->components->info("Tailwind CSS optimized: {$output}");

        return self::SUCCESS;
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);

        if ($version === '') {
            throw new RuntimeException('Tailwind CSS version cannot be empty.');
        }

        return str_starts_with($version, 'v') ? $version : "v{$version}";
    }

    private function binaryPath(string $version): string
    {
        $extension = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

        return storage_path("app/tools/tailwindcss/{$version}/tailwindcss{$extension}");
    }

    private function downloadBinary(string $version, string $binary): void
    {
        $asset = $this->releaseAssetName();
        $url = "https://github.com/tailwindlabs/tailwindcss/releases/download/{$version}/{$asset}";

        $this->components->info("Downloading Tailwind CSS {$version}...");

        File::ensureDirectoryExists(dirname($binary));

        $contents = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'header' => "User-Agent: Laravel-Nodeless\r\n",
                'timeout' => 60,
            ],
        ]));

        if ($contents === false) {
            throw new RuntimeException("Unable to download Tailwind CSS CLI from {$url}");
        }

        File::put($binary, $contents);

        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($binary, 0755);
        }
    }

    private function releaseAssetName(): string
    {
        $machine = strtolower(php_uname('m'));

        return match (PHP_OS_FAMILY) {
            'Darwin' => match ($machine) {
                'arm64', 'aarch64' => 'tailwindcss-macos-arm64',
                default => 'tailwindcss-macos-x64',
            },
            'Linux' => match ($machine) {
                'arm64', 'aarch64' => 'tailwindcss-linux-arm64',
                default => 'tailwindcss-linux-x64',
            },
            'Windows' => 'tailwindcss-windows-x64.exe',
            default => throw new RuntimeException('Unsupported operating system for the Tailwind CSS standalone CLI.'),
        };
    }
}
