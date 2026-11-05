<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\FinancialReportQuery;
use App\Exceptions\LedgerIntegrityException;
use App\Http\Requests\Reports\ShowFinancialReportRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Services\FinancialReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reportService,
    ) {}

    /**
     * Display the read-only Financial Reports workspace.
     */
    public function index(ShowFinancialReportRequest $request): Response
    {
        $user = $request->user();

        Gate::authorize('viewAny', FinancialAccount::class);
        Gate::authorize('viewAny', FinancialTransaction::class);

        $validated = $request->validated();
        $mode = (string) ($validated['mode'] ?? 'month');
        $now = CarbonImmutable::now('UTC');

        if ($mode === 'custom') {
            $startDate = (string) $validated['start_date'];
            $endDate = (string) $validated['end_date'];
            $month = null;
        } else {
            $month = isset($validated['month']) && is_string($validated['month']) && $validated['month'] !== ''
                ? (string) $validated['month']
                : $now->format('Y-m');
            $monthStart = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', 'UTC');
            if ($monthStart === false) {
                throw ValidationException::withMessages([
                    'month' => ['Month must use YYYY-MM format.'],
                ]);
            }
            $startDate = $monthStart->startOfMonth()->toDateString();
            $endDate = $monthStart->endOfMonth()->toDateString();
        }

        $accountId = isset($validated['financial_account_id'])
            ? (int) $validated['financial_account_id']
            : null;

        try {
            $report = $this->reportService->build(new FinancialReportQuery(
                user: $user,
                mode: $mode,
                startDate: $startDate,
                endDate: $endDate,
                month: $month,
                financialAccountId: $accountId,
            ));
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                'period' => [$exception->getMessage()],
            ]);
        }

        return Inertia::render('Reports/Index', $report);
    }
}
