<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

use ArtisanBuild\BuiltForCloud\Contracts\UsageReporter;
use ArtisanBuild\MatteServer\Commands\DoctorCommand;
use ArtisanBuild\MatteServer\Commands\ProvisionBinaryCommand;
use ArtisanBuild\MatteServer\Commands\RemoveCommand;
use Illuminate\Support\Facades\Route;
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
        $this->app->singleton(UsageReporter::class, MatteJobUsageReporter::class);

        Route::prefix((string) config('matte-server.route_prefix', ''))
            ->group(__DIR__.'/../routes/matte-server.php');

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
