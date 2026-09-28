<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient\Tests;

use ArtisanBuild\BfcClient\BfcClientServiceProvider;
use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\MatteClient\MatteClientServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BuiltForCloudServiceProvider::class, BfcClientServiceProvider::class, MatteClientServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('matte-client-key', 2)));
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
    }
}
