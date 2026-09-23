<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

it('ships a local session driver that persists across requests', function (): void {
    $environment = Dotenv::parse((string) file_get_contents(base_path('.env.example')));
    $sessionDriver = $environment['SESSION_DRIVER'] ?? null;

    expect($sessionDriver)->toBeString()->not->toBeEmpty();
    assert(is_string($sessionDriver));

    $configureSession = function () use ($sessionDriver): void {
        $this->app['config']->set('session.driver', $sessionDriver);
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        $this->app->bind(PreventRequestForgery::class, fn ($app): PreventRequestForgery => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    };

    $configureSession();
    $database = $this->app->make('db')->connection();
    $pdo = $database->getPdo();
    $email = 'local-admin@example.test';
    $password = 'test-created-password';

    expect(Artisan::call('create-admin', [
        '--local' => true,
        '--email' => $email,
        '--password' => $password,
        '--name' => 'Local Admin',
    ]))->toBe(Command::SUCCESS);

    $login = $this->get('/bfc/login')->assertOk();
    preg_match('/name="_token" value="([^"]+)"/', (string) $login->getContent(), $matches);

    expect($matches[1] ?? null)->toBeString()->not->toBeEmpty();

    $cookies = [];

    foreach ($login->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = (string) $cookie->getValue();
    }

    $this->refreshApplication();
    $configureSession();
    $this->app->make('db')->connection()->setPdo($pdo);

    $authenticated = $this->withUnencryptedCookies($cookies)
        ->post('/bfc/login', [
            '_token' => $matches[1],
            'email' => $email,
            'password' => $password,
        ])
        ->assertRedirect(route('bfc.ui.home', absolute: false));

    foreach ($authenticated->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = (string) $cookie->getValue();
    }

    $this->refreshApplication();
    $configureSession();
    $this->app->make('db')->connection()->setPdo($pdo);

    $this->withUnencryptedCookies($cookies)
        ->get('/bfc/ui')
        ->assertOk()
        ->assertSeeHtml('data-testid="ui-shell"')
        ->assertSee('Matte');
});
