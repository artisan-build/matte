<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient;

use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\MatteClient\Commands\InstallCommand;
use ArtisanBuild\MatteClient\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class MatteClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/matte.php', 'matte');
        $this->registerCallbackPurpose();

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

        if (($path = config('matte.callback.path')) !== null && $path !== '') {
            Route::post($path, WebhookController::class)->name('matte.callback');
        }
    }

    private function registerCallbackPurpose(): void
    {
        $purposes = config('built-for-cloud.credentials.app_purposes', []);
        $purposes = is_array($purposes) ? $purposes : [];
        $purposes[CallbackScope::APP_PURPOSE] = CredentialPurpose::Signing->value;
        config()->set('built-for-cloud.credentials.app_purposes', $purposes);

        $credentialPurposes = config('built-for-cloud.ui.credential_purposes', []);
        $credentialPurposes = is_array($credentialPurposes) ? $credentialPurposes : [];

        if (! in_array(CallbackScope::APP_PURPOSE, $credentialPurposes, true)) {
            $credentialPurposes[] = CallbackScope::APP_PURPOSE;
        }

        config()->set('built-for-cloud.ui.credential_purposes', $credentialPurposes);
    }
}
