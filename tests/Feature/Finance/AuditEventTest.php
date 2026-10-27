<?php

use App\DataTransferObjects\PostingInstruction;
use App\DataTransferObjects\PostJournalEntryInput;
use App\Exceptions\ImmutableRecordException;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FinancialAccountService;
use App\Services\LedgerPostingService;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
});

test('financial account lifecycle and journal postings generate append-only audit events without sensitive data', function (): void {
    $user = User::factory()->create();
    $accountService = app(FinancialAccountService::class);
    $postingService = app(LedgerPostingService::class);
    $auditService = app(AuditService::class);

    $checking = $accountService->createAccount($user, [
        'name' => 'Checking',
        'type' => 'checking',
        'currency_code' => 'PHP',
    ]);

    $savings = $accountService->createAccount($user, [
        'name' => 'Savings',
        'type' => 'savings',
        'currency_code' => 'PHP',
    ]);

    $accountService->updateAccount($user, $checking, [
        'name' => 'Primary Checking',
        'type' => 'checking',
    ]);

    $accountService->archiveAccount($user, $savings);
    $accountService->restoreAccount($user, $savings);

    $postingService->post(new PostJournalEntryInput(
        user: $user,
        currencyCode: 'PHP',
        entryDate: '2026-10-27',
        description: 'Initial transfer',
        idempotencyKey: 'audit-transfer-1',
        postings: [
            new PostingInstruction(ledgerAccountId: $savings->ledgerAccount->id, debitAmountMinor: 100000),
            new PostingInstruction(ledgerAccountId: $checking->ledgerAccount->id, creditAmountMinor: 100000),
        ],
    ));

    $actions = AuditEvent::query()
        ->where('user_id', $user->id)
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($actions)->toBe([
        AuditService::ACTION_ACCOUNT_CREATED,
        AuditService::ACTION_ACCOUNT_CREATED,
        AuditService::ACTION_ACCOUNT_UPDATED,
        AuditService::ACTION_ACCOUNT_ARCHIVED,
        AuditService::ACTION_ACCOUNT_RESTORED,
        AuditService::ACTION_JOURNAL_ENTRY_POSTED,
    ]);

    $redactedEvent = $auditService->record(
        actor: $user,
        action: 'custom.test',
        auditableType: User::class,
        auditableId: $user->id,
        metadata: [
            'safe_key' => 'visible',
            'password' => 'should-be-removed',
            'token' => 'should-be-removed',
            'secret' => 'should-be-removed',
        ],
    );

    expect($redactedEvent->metadata)->toBe(['safe_key' => 'visible']);

    expect(fn () => $redactedEvent->update(['action' => 'modified']))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $redactedEvent->delete())
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => DB::table('audit_events')->where('id', $redactedEvent->id)->update(['action' => 'raw-modified']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('audit_events')->where('id', $redactedEvent->id)->delete())
        ->toThrow(QueryException::class);
});
