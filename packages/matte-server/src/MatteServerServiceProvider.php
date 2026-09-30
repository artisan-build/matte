<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

use ArtisanBuild\MatteServer\Commands\DoctorCommand;
use ArtisanBuild\MatteServer\Commands\ProvisionBinaryCommand;
use ArtisanBuild\MatteServer\Commands\RemoveCommand;
use ArtisanBuild\MatteServer\Mcp\MatteMcpServer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

final class MatteServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/matte-server.php', 'matte-server');

        config()->set([
            'built-for-cloud.mcp.path' => config('matte-server.mcp.path'),
            'built-for-cloud.mcp.delegated' => config('matte-server.mcp.delegated'),
        ]);

        $this->app->singleton(BinaryLocator::class, fn (): BinaryLocator => BinaryLocator::fromSystem());
        $this->app->singleton(Converter::class);
    }

    public function boot(): void
    {
        Route::prefix((string) config('matte-server.route_prefix', ''))
            ->group(__DIR__.'/../routes/matte-server.php');

        $this->app->booted(function (): void {
            Mcp::web((string) config('matte-server.mcp.path'), MatteMcpServer::class);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
                ProvisionBinaryCommand::class,
                RemoveCommand::class,
            ]);

            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
