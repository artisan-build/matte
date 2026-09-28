<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer\Tests;

use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\MatteServer\MatteServerServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithCredentials {
        mintCredential as public;
    }

    protected MintedTestCredential $knownCredential;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BuiltForCloudServiceProvider::class, MatteServerServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('matte-server-key', 2)));
        $app['config']->set('built-for-cloud.manifest', [
            'name' => 'Matte',
            'slug' => 'matte',
            'description' => 'Background removal as an API you own: submit an image, poll the job, fetch a transparent PNG.',
            'icon' => 'https://scalpels.app/img/products/transparent/matte.png',
            'product_url' => 'https://scalpels.app/products/matte',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => User::class,
        ]);
        $app['config']->set('built-for-cloud.credentials.app_purposes', [
            'matte.remove' => CredentialPurpose::Consumption->value,
            'matte.callback' => CredentialPurpose::Signing->value,
        ]);
        $app['config']->set('built-for-cloud.ui.credential_purposes', [
            'matte.remove',
            'matte.callback',
        ]);
    }
}
