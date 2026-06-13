<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

use ArtisanBuild\MatteContracts\Mode;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\Exceptions\ConversionFailed;
use Illuminate\Support\Facades\Process;

final readonly class Converter
{
    public function __construct(
        private BinaryLocator $locator,
    ) {}

    public function convert(string $inputPath, string $outputPath, RemovalOptions $options): void
    {
        $command = $this->command($inputPath, $outputPath, $options);

        $result = Process::env($this->locator->executionEnv())
            ->timeout((int) config('matte-server.timeout', 120))
            ->run($command);

        if (! $result->successful()) {
            throw new ConversionFailed(sprintf(
                'Conversion failed with exit code %d.%s%s',
                $result->exitCode(),
                PHP_EOL,
                trim($result->errorOutput().$result->output()),
            ));
        }

        if (! is_file($outputPath)) {
            throw new ConversionFailed('Conversion completed without creating an output file.');
        }
    }

    public function dependenciesAvailable(): bool
    {
        if (! is_file($this->locator->binaryPath())) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $result = Process::timeout(30)->run(['ldd', $this->locator->binaryPath()]);
            $output = $result->output().$result->errorOutput();

            return $result->successful()
                && str_contains($output, 'libonnxruntime')
                && ! str_contains($output, 'not found');
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $result = Process::timeout(30)->run(['otool', '-L', $this->locator->binaryPath()]);

            return $result->successful()
                && $this->missingMacOsLibraries($result->output().$result->errorOutput()) === [];
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function missingMacOsLibraries(string $otoolOutput): array
    {
        $missing = [];

        foreach (preg_split('/\R/', $otoolOutput) ?: [] as $line) {
            $library = trim(explode(' ', trim($line))[0] ?? '');

            if ($library === '' || (! str_contains($library, 'opencv') && ! str_contains($library, 'onnxruntime'))) {
                continue;
            }

            $path = str_replace('@loader_path', dirname($this->locator->binaryPath()), $library);

            if (str_starts_with($path, '/') && ! is_file($path)) {
                $missing[] = $library;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public function command(string $inputPath, string $outputPath, RemovalOptions $options): array
    {
        $command = [
            $this->locator->binaryPath(),
            '-i',
            $inputPath,
            '-o',
            $outputPath,
            '-q',
            $options->preset->value,
        ];

        if ($options->mode === Mode::Grabcut) {
            $command[] = '--grabcut';
        }

        if ($options->mode === Mode::Ml) {
            $modelPath = $this->locator->modelPath($options->model ?? config('matte-server.model'));

            if (! is_file($modelPath)) {
                throw new ConversionFailed('ML conversion requested, but no model file is present at '.$modelPath.'.');
            }

            array_push($command, '--model', $modelPath);
        }

        if ($options->edgeMode !== null) {
            array_push($command, '--edge-mode', $options->edgeMode->value);
        }

        if ($options->iterations !== null) {
            array_push($command, '-n', (string) $options->iterations);
        }

        if ($options->margin !== null) {
            array_push($command, '-m', (string) $options->margin);
        }

        return $command;
    }
}
