<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

use ArtisanBuild\MatteServer\Commands\DoctorCommand;
use ArtisanBuild\MatteServer\Commands\ProvisionBinaryCommand;
use Illuminate\Support\ServiceProvider;

final class MatteServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/matte-server.php', 'matte-server');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
                ProvisionBinaryCommand::class,
            ]);
        }
    }
}
