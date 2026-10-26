<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', function (): Response {
    return Inertia::render('Welcome', [
        'productName' => config('app.name', 'Equity'),
        'tagline' => 'Personal finance, clearly understood.',
        'description' => 'Track your accounts, understand your spending, and plan your financial future in one place.',
        'status' => 'Application foundation',
    ]);
})->name('welcome');
