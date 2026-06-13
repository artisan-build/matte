<?php

declare(strict_types=1);

use ArtisanBuild\MatteServer\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

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
