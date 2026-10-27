<?php

namespace App\Services;

use App\Enums\JournalEntryStatus;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\JournalPosting;
use App\Models\LedgerAccount;
use App\Support\Money;

class AccountBalanceService
{
    /**
     * Derives the current posted balance for a single ledger account in its native currency.
     *
     * Normal balance conventions:
     * - Debit-normal accounts (Asset, Expense): total_debits - total_credits
     * - Credit-normal accounts (Liability, Equity, Income): total_credits - total_debits
     */
    public function getBalanceForLedgerAccount(LedgerAccount $ledgerAccount): Money
    {
        $ledgerAccount->loadMissing('currency');

        $balances = $this->getBalancesForLedgerAccounts([$ledgerAccount]);

        return $balances[$ledgerAccount->id] ?? Money::zero(
            $ledgerAccount->currency_code,
            $ledgerAccount->currency?->minor_unit_exponent ?? 2,
        );
    }

    /**
     * Derives balances for a collection of ledger accounts using a single aggregated query.
     *
     * @param  iterable<LedgerAccount>  $ledgerAccounts
     * @return array<int, Money>
     */
    public function getBalancesForLedgerAccounts(iterable $ledgerAccounts): array
    {
        $accountsById = [];

        foreach ($ledgerAccounts as $account) {
            $accountsById[$account->id] = $account;
        }

        if ($accountsById === []) {
            return [];
        }

        $currencyCodes = array_values(array_unique(array_map(
            static fn (LedgerAccount $account): string => $account->currency_code,
            $accountsById,
        )));

        $exponentsByCurrency = Currency::query()
            ->whereIn('code', $currencyCodes)
            ->pluck('minor_unit_exponent', 'code')
            ->all();

        $totals = JournalPosting::query()
            ->selectRaw(
                'journal_postings.ledger_account_id, COALESCE(SUM(journal_postings.debit_amount_minor), 0) AS total_debits, COALESCE(SUM(journal_postings.credit_amount_minor), 0) AS total_credits',
            )
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_postings.journal_entry_id')
            ->whereIn('journal_postings.ledger_account_id', array_keys($accountsById))
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->groupBy('journal_postings.ledger_account_id')
            ->get()
            ->keyBy('ledger_account_id');

        $results = [];

        foreach ($accountsById as $accountId => $ledgerAccount) {
            $row = $totals->get($accountId);
            $totalDebits = $row !== null ? (int) $row->total_debits : 0;
            $totalCredits = $row !== null ? (int) $row->total_credits : 0;

            $normalBalanceMinor = $ledgerAccount->classification->isDebitNormal()
                ? Money::checkedSubtract($totalDebits, $totalCredits)
                : Money::checkedSubtract($totalCredits, $totalDebits);

            $exponent = isset($exponentsByCurrency[$ledgerAccount->currency_code])
                ? (int) $exponentsByCurrency[$ledgerAccount->currency_code]
                : ($ledgerAccount->currency?->minor_unit_exponent ?? 2);

            $results[$accountId] = new Money(
                amountMinor: $normalBalanceMinor,
                currencyCode: $ledgerAccount->currency_code,
                exponent: $exponent,
            );
        }

        return $results;
    }

    /**
     * Derives balances and posting counts for a list of user-visible financial accounts without N+1 queries.
     *
     * @param  iterable<FinancialAccount>  $financialAccounts
     * @return array<int, array{
     *     amount_minor: int,
     *     currency_code: string,
     *     exponent: int,
     *     symbol: string,
     *     formatted: string,
     *     is_liability: bool,
     *     has_posted_activity: bool
     * }>
     */
    public function getBalancesForFinancialAccounts(iterable $financialAccounts): array
    {
        $accounts = [];
        $ledgerAccounts = [];

        foreach ($financialAccounts as $account) {
            $accounts[$account->id] = $account;
            if ($account->ledgerAccount !== null) {
                $ledgerAccounts[$account->ledgerAccount->id] = $account->ledgerAccount;
            }
        }

        if ($accounts === []) {
            return [];
        }

        $ledgerBalances = $this->getBalancesForLedgerAccounts($ledgerAccounts);

        $postingCounts = $ledgerAccounts !== []
            ? JournalPosting::query()
                ->selectRaw('ledger_account_id, COUNT(*) AS posting_count')
                ->whereIn('ledger_account_id', array_keys($ledgerAccounts))
                ->groupBy('ledger_account_id')
                ->pluck('posting_count', 'ledger_account_id')
                ->all()
            : [];

        $results = [];

        foreach ($accounts as $financialAccountId => $account) {
            $currency = $account->currency;
            $exponent = $currency !== null ? $currency->minor_unit_exponent : 2;
            $symbol = $currency !== null ? $currency->symbol : $account->currency_code.' ';
            $ledgerAccount = $account->ledgerAccount;

            $money = ($ledgerAccount !== null && isset($ledgerBalances[$ledgerAccount->id]))
                ? $ledgerBalances[$ledgerAccount->id]
                : Money::zero($account->currency_code, $exponent);

            $hasActivity = $ledgerAccount !== null
                && ((int) ($postingCounts[$ledgerAccount->id] ?? 0)) > 0;

            $results[$financialAccountId] = [
                'amount_minor' => $money->amountMinor,
                'currency_code' => $money->currencyCode,
                'exponent' => $money->exponent,
                'symbol' => $symbol,
                'formatted' => $money->format($symbol),
                'is_liability' => ! $account->type->ledgerClassification()->isDebitNormal(),
                'has_posted_activity' => $hasActivity,
            ];
        }

        return $results;
    }
}
