<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

use ArtisanBuild\MatteServer\Exceptions\UnsupportedPlatform;

final readonly class BinaryLocator
{
    public function __construct(
        private string $osFamily,
        private string $machine,
    ) {}

    public static function fromSystem(): self
    {
        return new self(PHP_OS_FAMILY, php_uname('m'));
    }

    public function binaryName(): string
    {
        return match ([$this->osFamily, $this->normalizedMachine()]) {
            ['Darwin', 'arm64'] => 'bg-remover-macos-arm64',
            ['Linux', 'arm64'] => 'bg-remover-linux-arm64',
            ['Linux', 'x86_64'] => 'bg-remover-ubuntu-x86_64',
            default => throw new UnsupportedPlatform("Unsupported platform [{$this->osFamily}/{$this->machine}]."),
        };
    }

    /**
     * @return array{tgz: string, lib: string}
     */
    public function onnxAsset(): array
    {
        $version = (string) config('matte-server.onnx_version', '1.19.2');

        return match ([$this->osFamily, $this->normalizedMachine()]) {
            ['Darwin', 'arm64'] => [
                'tgz' => "onnxruntime-osx-arm64-{$version}.tgz",
                'lib' => "libonnxruntime.{$version}.dylib",
            ],
            ['Linux', 'arm64'] => [
                'tgz' => "onnxruntime-linux-aarch64-{$version}.tgz",
                'lib' => 'libonnxruntime.so.1',
            ],
            ['Linux', 'x86_64'] => [
                'tgz' => "onnxruntime-linux-x64-{$version}.tgz",
                'lib' => 'libonnxruntime.so.1',
            ],
            default => throw new UnsupportedPlatform("Unsupported platform [{$this->osFamily}/{$this->machine}]."),
        };
    }

    public function runtimePath(): string
    {
        return (string) config('matte-server.runtime_path');
    }

    public function binaryPath(): string
    {
        return $this->runtimePath().'/bin/'.$this->binaryName();
    }

    public function libDir(): string
    {
        return $this->runtimePath().'/bin/lib';
    }

    public function modelPath(?string $model = null): string
    {
        $model ??= (string) config('matte-server.model', 'model.onnx');

        return $this->runtimePath().'/models/'.$model;
    }

    /**
     * @return array<string, string>
     */
    public function executionEnv(): array
    {
        if ($this->osFamily !== 'Darwin') {
            return [];
        }

        return ['DYLD_LIBRARY_PATH' => $this->libDir()];
    }

    public function isProvisioned(): bool
    {
        $onnx = $this->onnxAsset();

        return is_file($this->binaryPath())
            && is_executable($this->binaryPath())
            && is_file($this->libDir().'/'.$onnx['lib']);
    }

    private function normalizedMachine(): string
    {
        return match ($this->machine) {
            'aarch64' => 'arm64',
            default => $this->machine,
        };
    }
}
