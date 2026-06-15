<?php

use Illuminate\Support\Facades\Artisan;

it('boots the headless Matte app root route', function (): void {
    $this->getJson(route('home'))
        ->assertOk()
        ->assertJson([
            'name' => 'Matte',
            'status' => 'ok',
        ]);
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
