<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

use ArtisanBuild\MatteServer\Commands\DoctorCommand;
use ArtisanBuild\MatteServer\Commands\ProvisionBinaryCommand;
use ArtisanBuild\MatteServer\Commands\RemoveCommand;
use Illuminate\Support\ServiceProvider;

final class MatteServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/matte-server.php', 'matte-server');

        $this->app->singleton(BinaryLocator::class, fn (): BinaryLocator => BinaryLocator::fromSystem());
        $this->app->singleton(Converter::class);
    }

    public function boot(): void
    {
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
