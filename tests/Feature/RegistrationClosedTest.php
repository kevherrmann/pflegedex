<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('does not expose public self registration', function () {
    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Offener Zugang',
        'email' => 'offen@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::query()->where('email', 'offen@example.test')->exists())->toBeFalse();
});

it('does not advertise self registration on the welcome page', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('canRegister', false)
        );
});
