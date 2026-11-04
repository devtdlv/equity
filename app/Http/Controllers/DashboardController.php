<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\FinancialAccount;
use App\Models\RecurringOccurrence;
use App\Models\SavingsGoal;
use App\Services\DashboardOverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardOverviewService $overviewService,
    ) {}

    /**
     * Display the authenticated financial overview (read-only).
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        Gate::authorize('viewAny', FinancialAccount::class);
        Gate::authorize('viewAny', Budget::class);
        Gate::authorize('viewAny', RecurringOccurrence::class);
        Gate::authorize('viewAny', SavingsGoal::class);

        $overview = $this->overviewService->build($user);

        return Inertia::render('Dashboard', $overview->toInertiaProps());
    }
}
