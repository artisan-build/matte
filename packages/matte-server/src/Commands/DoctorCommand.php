<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Commands;

use ArtisanBuild\MatteContracts\Mode;
use ArtisanBuild\MatteContracts\Preset;
use ArtisanBuild\MatteContracts\RemovalOptions;
use ArtisanBuild\MatteServer\BinaryLocator;
use ArtisanBuild\MatteServer\Converter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'matte:doctor';

    protected $description = 'Verify the Matte binary runtime and run a real conversion.';

    public function handle(Converter $converter): int
    {
        $locator = BinaryLocator::fromSystem();
        $onnx = $locator->onnxAsset();
        $checks = [];

        $checks[] = $this->check(
            'Binary present and executable',
            is_file($locator->binaryPath()) && is_executable($locator->binaryPath()),
            $locator->binaryPath(),
        );

        $libraryPath = $locator->libDir().'/'.$onnx['lib'];

        $checks[] = $this->check(
            'ONNX Runtime library present',
            is_file($libraryPath),
            $libraryPath,
        );

        $checks[] = $this->dependencyCheck($locator, $converter);
        $checks[] = $this->conversionCheck($converter, end($checks) === true);

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function dependencyCheck(BinaryLocator $locator, Converter $converter): bool
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $result = Process::timeout(30)->run(['ldd', $locator->binaryPath()]);
            $output = $result->output().$result->errorOutput();
            $passed = $converter->dependenciesAvailable();

            return $this->check('Linux dynamic library resolution', $passed, trim($output));
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $result = Process::timeout(30)->run(['otool', '-L', $locator->binaryPath()]);
            $output = trim($result->output().$result->errorOutput());
            $missing = $result->successful() ? $converter->missingMacOsLibraries($output) : [];
            $passed = $converter->dependenciesAvailable();

            return $this->check(
                'macOS dynamic library dependencies',
                $passed,
                $passed ? $output : $this->macOsRemediation($missing, $output),
            );
        }

        return $this->check('Dynamic library resolution', false, 'Unsupported operating system.');
    }

    /**
     * @param  list<string>  $missing
     */
    private function macOsRemediation(array $missing, string $details): string
    {
        $message = 'macOS: run `brew install opencv onnxruntime`';

        if ($missing !== []) {
            $message .= PHP_EOL.'Missing: '.implode(', ', $missing);
        }

        if ($details !== '') {
            $message .= PHP_EOL.$details;
        }

        return $message;
    }

    private function conversionCheck(Converter $converter, bool $dependenciesAvailable): bool
    {
        if (PHP_OS_FAMILY === 'Darwin' && ! $dependenciesAvailable) {
            $this->line('SKIP Real grabcut conversion');
            $this->line('macOS: run `brew install opencv onnxruntime`');

            return true;
        }

        $samplePath = __DIR__.'/../../resources/doctor-sample.png';
        $outputPath = tempnam(sys_get_temp_dir(), 'matte-doctor-');

        if ($outputPath === false) {
            return $this->check('Real grabcut conversion', false, 'Unable to create temporary output path.');
        }

        $pngOutputPath = $outputPath.'.png';
        rename($outputPath, $pngOutputPath);

        try {
            $converter->convert($samplePath, $pngOutputPath, new RemovalOptions(Mode::Grabcut, Preset::Fast));
            $passed = $this->isPngWithAlpha($pngOutputPath);

            $details = $passed
                ? 'Conversion output: '.$pngOutputPath
                : $this->conversionFailureDetails('Output is not a PNG with alpha.');
        } catch (Throwable $exception) {
            $passed = false;
            $details = $this->conversionFailureDetails($exception->getMessage());
        }

        return $this->check('Real grabcut conversion', $passed, $details);
    }

    private function conversionFailureDetails(string $details): string
    {
        $details = trim($details);

        if (PHP_OS_FAMILY !== 'Darwin') {
            return $details;
        }

        return $details.PHP_EOL.'macOS: run `brew install opencv onnxruntime`';
    }

    private function check(string $label, bool $passed, string $details): bool
    {
        $this->line(($passed ? 'PASS' : 'FAIL').' '.$label);

        if ($details !== '') {
            $this->line($details);
        }

        return $passed;
    }

    private function isPngWithAlpha(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $contents = file_get_contents($path, false, null, 0, 26);

        if (! is_string($contents) || strlen($contents) < 26) {
            return false;
        }

        return substr($contents, 0, 8) === "\x89PNG\r\n\x1A\n"
            && ord($contents[25]) === 6;
    }
}
