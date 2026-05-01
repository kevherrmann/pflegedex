<?php

use App\Models\User;

it('does not render a public registration screen', function () {
    $this->get('/register')->assertNotFound();
});

it('does not allow public self registration', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});
