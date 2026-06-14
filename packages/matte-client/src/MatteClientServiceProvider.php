<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient;

use ArtisanBuild\MatteClient\Commands\InstallCommand;
use ArtisanBuild\MatteClient\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class MatteClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/matte.php', 'matte');

        $this->app->singleton(MatteClient::class, fn (): MatteClient => new MatteClient(
            url: config('matte.url'),
            token: config('matte.token'),
        ));

        $this->app->alias(MatteClient::class, 'matte');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/matte.php' => config_path('matte.php'),
        ], 'matte-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }

        if (($path = config('matte.webhook_path')) !== null && $path !== '') {
            Route::post($path, WebhookController::class)->name('matte.webhook');
        }
    }
}
