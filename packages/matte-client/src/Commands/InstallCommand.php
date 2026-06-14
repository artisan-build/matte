<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class InstallCommand extends Command
{
    protected $signature = 'matte:install';

    protected $description = 'Install and configure the Matte client SDK.';

    public function handle(): int
    {
        $url = text(label: 'Matte server URL', required: true);
        $token = password(label: 'Matte API token', required: true);
        $webhookSecret = password(label: 'Matte webhook secret (optional)', required: false);

        $this->writeEnvironment([
            'MATTE_URL' => $url,
            'MATTE_TOKEN' => $token,
            'MATTE_WEBHOOK_SECRET' => $webhookSecret,
        ]);

        Artisan::call('vendor:publish', [
            '--provider' => MatteClientServiceProvider::class,
            '--tag' => 'matte-config',
        ]);

        $this->components->info('Matte client installed. Configure MATTE_WEBHOOK_PATH if you want to receive signed completion webhooks.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnvironment(array $values): void
    {
        $path = base_path('.env');
        $contents = file_exists($path) ? (string) file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            if ($value === '') {
                continue;
            }

            $line = $key.'='.$this->environmentValue($value);

            if (preg_match("/^{$key}=.*$/m", $contents) === 1) {
                $contents = preg_replace("/^{$key}=.*$/m", $line, $contents) ?? $contents;
            } else {
                $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
            }
        }

        file_put_contents($path, ltrim($contents));
    }

    private function environmentValue(string $value): string
    {
        if (preg_match('/\s|#|=|"/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace('"', '\\"', $value).'"';
    }
}
