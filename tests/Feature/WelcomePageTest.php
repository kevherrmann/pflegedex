<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the Pflegedex landing page with Sander-inspired product copy', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('branding.name', 'Pflegedex')
            ->where('branding.primaryColor', '#9B1C3B')
        );
});
