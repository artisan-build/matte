<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('provisions the current platform and proves conversion when host dependencies are available', function (): void {
    $runtimePath = sys_get_temp_dir().'/matte-runtime-test-'.bin2hex(random_bytes(6));

    config()->set('matte-server.runtime_path', $runtimePath);
    config()->set('matte-server.onnx_version', '1.19.2');

    try {
        $provisionExitCode = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $provisionExitCode = Artisan::call('matte:provision-binary');

            if ($provisionExitCode === 0) {
                break;
            }

            usleep($attempt * 250_000);
        }

        expect($provisionExitCode)->toBe(0);

        $doctorExitCode = Artisan::call('matte:doctor');
        $doctorOutput = Artisan::output();

        if (PHP_OS_FAMILY === 'Darwin' && str_contains($doctorOutput, 'macOS: run `brew install opencv onnxruntime`')) {
            expect($doctorExitCode)->toBe(1)
                ->and($doctorOutput)->toContain('SKIP Real grabcut conversion')
                ->and($doctorOutput)->toContain('macOS: run `brew install opencv onnxruntime`');

            return;
        }

        expect($doctorExitCode)->toBe(0)
            ->and($doctorOutput)->toContain('PASS Real grabcut conversion');

        preg_match('/Conversion output: (?<path>.+\.png)/', $doctorOutput, $matches);

        expect($matches['path'] ?? null)->toBeString();

        $outputPath = trim($matches['path']);

        expect(isPngWithAlpha($outputPath))->toBeTrue();

        @unlink($outputPath);
    } finally {
        removeDirectory($runtimePath);
    }
});

function isPngWithAlpha(string $path): bool
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

function removeDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($path);
}
