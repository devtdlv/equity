<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\LinkRecurringPaymentInput;
use App\DataTransferObjects\OccurrencePaymentSummary;
use App\DataTransferObjects\RecurringPaymentLinkSummary;
use App\DataTransferObjects\UnlinkRecurringPaymentInput;
use App\Exceptions\LedgerIntegrityException;
use App\Http\Requests\Recurring\DestroyRecurringPaymentLinkRequest;
use App\Http\Requests\Recurring\IndexRecurringOccurrenceRequest;
use App\Http\Requests\Recurring\ShowRecurringOccurrenceRequest;
use App\Http\Requests\Recurring\SkipRecurringOccurrenceRequest;
use App\Http\Requests\Recurring\StoreRecurringPaymentLinkRequest;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPaymentLink;
use App\Services\RecurringObligationService;
use App\Services\RecurringPaymentService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RecurringOccurrenceController extends Controller
{
    public const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private readonly RecurringObligationService $obligationService,
        private readonly RecurringPaymentService $paymentService,
    ) {}

    public function index(IndexRecurringOccurrenceRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);

        $query = RecurringOccurrence::query()
            ->with([
                'obligation:id,user_id,name,currency_code,category_id,status',
                'currency:code,name,symbol,minor_unit_exponent',
            ])
            ->where('user_id', $user->id)
            ->orderBy('due_date')
            ->orderBy('id');

        if (! empty($validated['from_date'])) {
            $query->whereDate('due_date', '>=', $validated['from_date']);
        }

        if (! empty($validated['to_date'])) {
            $query->whereDate('due_date', '<=', $validated['to_date']);
        }

        if (! empty($validated['currency_code'])) {
            $query->where('currency_code', $validated['currency_code']);
        }

        if (! empty($validated['obligation_id'])) {
            $query->where('obligation_id', (int) $validated['obligation_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        /** @var list<RecurringOccurrence> $occurrences */
        $occurrences = $paginator->items();
        $summaries = $this->paymentService->getOccurrencePaymentSummaries($user, $occurrences);

        $items = [];
        foreach ($occurrences as $occurrence) {
            $summary = $summaries[(int) $occurrence->id] ?? null;
            if ($summary === null) {
                throw new LedgerIntegrityException('Occurrence payment summary could not be resolved.');
            }
            $items[] = $this->serializeOccurrence($summary);
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'from_date' => $validated['from_date'] ?? null,
                'to_date' => $validated['to_date'] ?? null,
                'currency_code' => $validated['currency_code'] ?? null,
                'obligation_id' => isset($validated['obligation_id']) ? (int) $validated['obligation_id'] : null,
                'status' => $validated['status'] ?? null,
            ],
        ]);
    }

    public function show(
        ShowRecurringOccurrenceRequest $request,
        RecurringOccurrence $occurrence,
    ): JsonResponse {
        $occurrence->loadMissing([
            'obligation:id,user_id,name,currency_code,category_id,status',
            'currency:code,name,symbol,minor_unit_exponent',
        ]);

        $summary = $this->paymentService->getOccurrencePaymentSummary(
            $request->user(),
            (int) $occurrence->id,
        );

        return response()->json([
            'data' => $this->serializeOccurrence($summary),
        ]);
    }

    public function skip(
        SkipRecurringOccurrenceRequest $request,
        RecurringOccurrence $occurrence,
    ): JsonResponse {
        try {
            $skipped = $this->obligationService->skipOccurrence(
                $request->user(),
                (int) $occurrence->id,
            );
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                'occurrence' => [$exception->getMessage()],
            ]);
        }

        $skipped->loadMissing([
            'obligation:id,user_id,name,currency_code,category_id,status',
            'currency:code,name,symbol,minor_unit_exponent',
        ]);

        $summary = $this->paymentService->getOccurrencePaymentSummary(
            $request->user(),
            (int) $skipped->id,
        );

        return response()->json([
            'data' => $this->serializeOccurrence($summary),
        ]);
    }

    public function storePaymentLink(
        StoreRecurringPaymentLinkRequest $request,
        RecurringOccurrence $occurrence,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $result = $this->paymentService->linkPayment(new LinkRecurringPaymentInput(
                user: $request->user(),
                occurrenceId: (int) $occurrence->id,
                financialTransactionId: (int) $validated['financial_transaction_id'],
            ));
        } catch (LedgerIntegrityException $exception) {
            if ($this->isPaymentLinkConflict($exception)) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => [
                        'financial_transaction_id' => [$exception->getMessage()],
                    ],
                ], 409);
            }

            throw ValidationException::withMessages([
                $this->mapPaymentLinkIntegrityField($exception->getMessage()) => [$exception->getMessage()],
            ]);
        }

        $link = $result->paymentLink->loadMissing([
            'financialTransaction:id,user_id,type,amount_minor,currency_code,category_id,entry_date,description',
        ]);

        return response()->json([
            'data' => [
                'payment_link' => $this->serializePaymentLink($link),
                'occurrence' => $this->serializeOccurrence($result->paymentSummary),
            ],
            'meta' => [
                'was_recently_created' => $result->wasRecentlyCreated,
                'idempotent_replay' => ! $result->wasRecentlyCreated,
            ],
        ], $result->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyPaymentLink(
        DestroyRecurringPaymentLinkRequest $request,
        RecurringOccurrence $occurrence,
        RecurringPaymentLink $link,
    ): JsonResponse {
        try {
            $result = $this->paymentService->unlinkPayment(new UnlinkRecurringPaymentInput(
                user: $request->user(),
                occurrenceId: (int) $occurrence->id,
                financialTransactionId: (int) $link->financial_transaction_id,
            ));
        } catch (LedgerIntegrityException $exception) {
            throw ValidationException::withMessages([
                $this->mapPaymentUnlinkIntegrityField($exception->getMessage()) => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'data' => [
                'occurrence_id' => $result->occurrenceId,
                'financial_transaction_id' => $result->financialTransactionId,
                'occurrence' => $this->serializeOccurrence($result->paymentSummary),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOccurrence(OccurrencePaymentSummary $summary): array
    {
        $occurrence = $summary->occurrence->loadMissing([
            'obligation:id,user_id,name,currency_code,category_id,status',
            'currency:code,name,symbol,minor_unit_exponent',
        ]);
        $obligation = $occurrence->obligation;
        $currency = $occurrence->currency;

        return [
            'id' => (int) $occurrence->id,
            'obligation_id' => (int) $occurrence->obligation_id,
            'obligation_name' => $obligation?->name,
            'due_date' => $occurrence->due_date->toDateString(),
            'currency_code' => $summary->currencyCode,
            'expected_amount_minor' => $summary->expectedAmountMinor,
            'paid_amount_minor' => $summary->paidAmountMinor,
            'remaining_amount_minor' => $summary->remainingAmountMinor,
            'overpaid_amount_minor' => $summary->overpaidAmountMinor,
            'status' => $occurrence->status instanceof \BackedEnum
                ? $occurrence->status->value
                : (string) $occurrence->status,
            'display_status' => $summary->displayStatus->value,
            'is_overdue' => $summary->isOverdue,
            'is_due_today' => $summary->isDueToday,
            'is_partially_paid' => $summary->isPartiallyPaid,
            'is_paid' => $summary->isPaid,
            'active_slot_key' => $occurrence->active_slot_key instanceof CarbonInterface
                ? $occurrence->active_slot_key->toDateString()
                : ($occurrence->active_slot_key !== null ? (string) $occurrence->active_slot_key : null),
            'skipped_at' => optional($occurrence->skipped_at)?->toIso8601String(),
            'cancelled_at' => optional($occurrence->cancelled_at)?->toIso8601String(),
            'active_linked_transaction_ids' => $summary->activeLinkedTransactionIds,
            'all_linked_transaction_ids' => $summary->allLinkedTransactionIds,
            'payment_links' => array_map(
                fn (RecurringPaymentLinkSummary $link): array => $this->serializePaymentLinkSummary($link),
                $summary->paymentLinks,
            ),
            'currency' => $currency === null ? null : [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'minor_unit_exponent' => (int) $currency->minor_unit_exponent,
            ],
            'created_at' => optional($occurrence->created_at)?->toIso8601String(),
            'updated_at' => optional($occurrence->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePaymentLinkSummary(RecurringPaymentLinkSummary $link): array
    {
        return [
            'id' => $link->id,
            'occurrence_id' => $link->occurrenceId,
            'financial_transaction_id' => $link->financialTransactionId,
            'amount_minor' => $link->amountMinor,
            'currency_code' => $link->currencyCode,
            'is_active' => $link->isActive,
            'is_reversed' => $link->isReversed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePaymentLink(RecurringPaymentLink $link): array
    {
        $transaction = $link->relationLoaded('financialTransaction') ? $link->financialTransaction : null;

        return [
            'id' => (int) $link->id,
            'occurrence_id' => (int) $link->occurrence_id,
            'financial_transaction_id' => (int) $link->financial_transaction_id,
            'currency_code' => $link->currency_code,
            'financial_transaction' => $transaction === null ? null : [
                'id' => (int) $transaction->id,
                'type' => $transaction->type instanceof \BackedEnum
                    ? $transaction->type->value
                    : (string) $transaction->type,
                'amount_minor' => (int) $transaction->amount_minor,
                'currency_code' => $transaction->currency_code,
                'category_id' => $transaction->category_id !== null ? (int) $transaction->category_id : null,
                'entry_date' => $transaction->entry_date->toDateString(),
                'description' => $transaction->description,
            ],
            'created_at' => optional($link->created_at)?->toIso8601String(),
            'updated_at' => optional($link->updated_at)?->toIso8601String(),
        ];
    }

    private function isPaymentLinkConflict(LedgerIntegrityException $exception): bool
    {
        $lower = strtolower($exception->getMessage());

        return str_contains($lower, 'already linked');
    }

    private function mapPaymentLinkIntegrityField(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'transaction') || str_contains($lower, 'expense') || str_contains($lower, 'reversal') || str_contains($lower, 'reversed') || str_contains($lower, 'active')) {
            return 'financial_transaction_id';
        }

        if (str_contains($lower, 'currency')) {
            return 'financial_transaction_id';
        }

        if (str_contains($lower, 'categor')) {
            return 'financial_transaction_id';
        }

        if (str_contains($lower, 'occurrence') || str_contains($lower, 'skipped') || str_contains($lower, 'cancelled') || str_contains($lower, 'scheduled')) {
            return 'occurrence';
        }

        if (str_contains($lower, 'overflow')) {
            return 'financial_transaction_id';
        }

        return 'payment_link';
    }

    private function mapPaymentUnlinkIntegrityField(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'no payment link')) {
            return 'link';
        }

        return 'payment_link';
    }
}
