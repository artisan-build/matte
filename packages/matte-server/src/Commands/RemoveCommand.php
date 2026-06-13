<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Commands;

use ArtisanBuild\MatteContracts\EdgeMode;
use ArtisanBuild\MatteContracts\Mode;
use ArtisanBuild\MatteContracts\Preset;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\Converter;
use ArtisanBuild\MatteServer\Exceptions\ConversionFailed;
use Illuminate\Console\Command;
use Throwable;

final class RemoveCommand extends Command
{
    protected $signature = 'matte:remove
        {input : Input image path}
        {--mode=grabcut : Removal mode: grabcut or ml}
        {--grabcut : Use grabcut mode}
        {--preset=balanced : Quality preset: fast, balanced, or quality}
        {--model= : Model filename}
        {--edge-mode= : Edge mode: blur, bilateral, or guided}
        {--iterations= : Grabcut iterations}
        {--margin= : Grabcut margin}
        {--out= : Output image path}';

    protected $description = 'Synchronously remove the background from an image.';

    public function handle(Converter $converter): int
    {
        if (! $converter->dependenciesAvailable()) {
            $this->error('Matte binary dependencies are not available.');
            $this->line('macOS: run `brew install opencv onnxruntime`');

            return self::FAILURE;
        }

        $inputPath = (string) $this->argument('input');
        $outputPath = $this->outputPath($inputPath);

        try {
            $converter->convert($inputPath, $outputPath, $this->removalOptions());
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Background removed: '.$outputPath);

        return self::SUCCESS;
    }

    private function removalOptions(): RemovalOptions
    {
        $modeValue = $this->option('grabcut') === true ? Mode::Grabcut->value : (string) $this->option('mode');
        $mode = Mode::tryFrom($modeValue);
        $preset = Preset::tryFrom((string) $this->option('preset'));
        $edgeModeValue = $this->option('edge-mode');
        $edgeMode = $edgeModeValue === null || $edgeModeValue === '' ? null : EdgeMode::tryFrom((string) $edgeModeValue);

        if ($mode === null) {
            throw new ConversionFailed('Invalid mode. Expected grabcut or ml.');
        }

        if ($preset === null) {
            throw new ConversionFailed('Invalid preset. Expected fast, balanced, or quality.');
        }

        if (($edgeModeValue !== null && $edgeModeValue !== '') && $edgeMode === null) {
            throw new ConversionFailed('Invalid edge mode. Expected blur, bilateral, or guided.');
        }

        return new RemovalOptions(
            mode: $mode,
            preset: $preset,
            model: $this->nullableStringOption('model'),
            edgeMode: $edgeMode,
            iterations: $this->nullableIntegerOption('iterations'),
            margin: $this->nullableIntegerOption('margin'),
        );
    }

    private function outputPath(string $inputPath): string
    {
        $out = $this->option('out');

        if (is_string($out) && $out !== '') {
            return $out;
        }

        $directory = dirname($inputPath);
        $filename = pathinfo($inputPath, PATHINFO_FILENAME).'-removed.png';

        return ($directory === '.' ? '' : $directory.'/').$filename;
    }

    private function nullableStringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableIntegerOption(string $key): ?int
    {
        $value = $this->option($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new ConversionFailed('Invalid '.$key.'. Expected a positive integer.');
        }

        return (int) $value;
    }
}
