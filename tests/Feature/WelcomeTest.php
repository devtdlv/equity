<?php

use Inertia\Testing\AssertableInertia;

test('the welcome route returns a successful inertia response for guests without authentication', function (): void {
    $this->assertGuest();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Welcome')
    );
});

test('the welcome route provides the Equity product name, tagline, description, and foundation status', function (): void {
    $response = $this->get(route('welcome'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Welcome')
        ->where('productName', 'Equity')
        ->where('tagline', 'Personal finance, clearly understood.')
        ->where(
            'description',
            'Track your accounts, understand your spending, and plan your financial future in one place.'
        )
        ->where('status', 'Application foundation')
        ->where('app.name', 'Equity')
    );
});
