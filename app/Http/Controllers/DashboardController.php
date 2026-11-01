<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the authenticated navigation-first workspace overview.
     *
     * This page uses shared Inertia props only. It does not load financial
     * totals, module counts, or other domain queries.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard');
    }
}
