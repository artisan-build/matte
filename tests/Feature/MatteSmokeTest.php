<?php

use Illuminate\Support\Facades\Artisan;

it('serves the package-owned Matte landing page', function (): void {
    $this->get(route('bfc.landing'))
        ->assertOk()
        ->assertSee('Matte')
        ->assertSee(route('bfc.dashboard'), false);
});

it('keeps the health endpoint public', function (): void {
    $this->get('/up')->assertOk();
});

it('rejects remove requests without a bearer token', function (): void {
    $this->postJson(route('matte.remove'), [])
        ->assertUnauthorized();
});

it('registers the Matte server console commands', function (): void {
    expect(Artisan::all())->toHaveKeys([
        'matte:doctor',
        'matte:provision-binary',
        'matte:remove',
    ]);
});
