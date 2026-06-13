<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Commands;

use ArtisanBuild\MatteServer\BinaryLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

final class ProvisionBinaryCommand extends Command
{
    protected $signature = 'matte:provision-binary {--force}';

    protected $description = 'Download and install the bg-remover binary and ONNX Runtime library.';

    public function handle(): int
    {
        $locator = BinaryLocator::fromSystem();
        $force = (bool) $this->option('force');
        $onnx = $locator->onnxAsset();

        $this->ensureDirectory($locator->runtimePath().'/bin');
        $this->ensureDirectory($locator->libDir());
        $this->ensureDirectory($locator->runtimePath().'/models');

        $binaryInstalled = $this->provisionBinary($locator, $force);
        $onnxInstalled = $this->provisionOnnxRuntime($locator, $onnx, $force);
        $modelInstalled = $this->provisionModel($locator, $force);

        $this->components->info('Matte binary provisioning complete.');
        $this->line('Binary: '.$locator->binaryPath().' ('.$binaryInstalled.')');
        $this->line('ONNX Runtime: '.$locator->libDir().'/'.$onnx['lib'].' ('.$onnxInstalled.')');

        if ($modelInstalled !== null) {
            $this->line('Model: '.$modelInstalled);
        }

        return self::SUCCESS;
    }

    private function provisionBinary(BinaryLocator $locator, bool $force): string
    {
        if (! $force && is_file($locator->binaryPath()) && is_executable($locator->binaryPath())) {
            return 'already present';
        }

        $tag = (string) config('matte-server.bg_remover_tag', 'v0.7.1');
        $asset = $locator->binaryName();
        $baseUrl = "https://github.com/artisan-build/bg-remover/releases/download/{$tag}";
        $binaryPath = $locator->binaryPath();

        $checksums = $this->download("{$baseUrl}/checksums.txt");
        $expectedHash = $this->checksumForAsset($checksums, $asset);
        $temporaryPath = $binaryPath.'.download';

        file_put_contents($temporaryPath, $this->download("{$baseUrl}/{$asset}"));

        $actualHash = hash_file('sha256', $temporaryPath);

        if ($actualHash !== $expectedHash) {
            @unlink($temporaryPath);

            throw new RuntimeException("Checksum mismatch for {$asset}.");
        }

        rename($temporaryPath, $binaryPath);
        chmod($binaryPath, 0755);

        return 'downloaded';
    }

    /**
     * @param  array{tgz: string, lib: string}  $onnx
     */
    private function provisionOnnxRuntime(BinaryLocator $locator, array $onnx, bool $force): string
    {
        $libraryPath = $locator->libDir().'/'.$onnx['lib'];

        if (! $force && is_file($libraryPath)) {
            return 'already present';
        }

        $version = (string) config('matte-server.onnx_version', '1.19.2');
        $archivePath = $locator->runtimePath().'/'.$onnx['tgz'];
        $extractPath = $locator->runtimePath().'/onnxruntime-extract';

        file_put_contents(
            $archivePath,
            $this->download("https://github.com/microsoft/onnxruntime/releases/download/v{$version}/{$onnx['tgz']}")
        );

        $this->removeDirectory($extractPath);
        $this->ensureDirectory($extractPath);

        $result = Process::timeout(120)->run(['tar', '-xzf', $archivePath, '-C', $extractPath]);

        if ($result->failed()) {
            throw new RuntimeException('Unable to extract ONNX Runtime: '.$result->errorOutput());
        }

        $extractedLibrary = $this->findExtractedLibrary($extractPath, $onnx['lib']);

        if ($extractedLibrary === null) {
            throw new RuntimeException("Unable to locate {$onnx['lib']} in ONNX Runtime archive.");
        }

        copy($extractedLibrary, $libraryPath);
        @unlink($archivePath);
        $this->removeDirectory($extractPath);

        return 'downloaded';
    }

    private function provisionModel(BinaryLocator $locator, bool $force): ?string
    {
        $modelUrl = config('matte-server.model_url');

        if (! is_string($modelUrl) || $modelUrl === '') {
            return null;
        }

        $modelName = config('matte-server.model');

        if (! is_string($modelName) || $modelName === '') {
            $modelName = basename((string) parse_url($modelUrl, PHP_URL_PATH));
        }

        if ($modelName === '') {
            throw new RuntimeException('Unable to determine model filename. Configure matte-server.model.');
        }

        $modelPath = $locator->modelPath($modelName);

        if (! $force && is_file($modelPath)) {
            return $modelPath.' (already present)';
        }

        file_put_contents($modelPath, $this->download($modelUrl));

        return $modelPath.' (downloaded)';
    }

    private function download(string $url): string
    {
        return Http::retry([250, 500, 1000])
            ->timeout(120)
            ->connectTimeout(15)
            ->get($url)
            ->throw()
            ->body();
    }

    private function checksumForAsset(string $checksums, string $asset): string
    {
        foreach (preg_split('/\R/', $checksums) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_ends_with($line, $asset)) {
                continue;
            }

            $hash = Str::of($line)->before(' ')->trim()->toString();

            if (preg_match('/^[a-f0-9]{64}$/i', $hash) === 1) {
                return strtolower($hash);
            }
        }

        throw new RuntimeException("Unable to find checksum for {$asset}.");
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function findExtractedLibrary(string $path, string $library): ?string
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $library) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
