<?php

namespace App\Services;

use App\Enums\FinancialAccountType;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FinancialAccountService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Atomically creates a user-visible financial account, its 1:1 ledger account, and an audit event.
     *
     * @param  array{
     *     name: string,
     *     type: FinancialAccountType|string,
     *     currency_code: string,
     *     institution?: string|null,
     *     description?: string|null
     * }  $data
     */
    public function createAccount(User $user, array $data): FinancialAccount
    {
        $accountType = $data['type'] instanceof FinancialAccountType
            ? $data['type']
            : FinancialAccountType::from((string) $data['type']);

        $currencyCode = strtoupper(trim((string) $data['currency_code']));
        $trimmedName = trim($data['name']);
        $institution = isset($data['institution']) && trim($data['institution']) !== ''
            ? trim($data['institution'])
            : null;
        $description = isset($data['description']) && trim($data['description']) !== ''
            ? trim($data['description'])
            : null;

        return DB::transaction(function () use ($user, $accountType, $currencyCode, $trimmedName, $institution, $description): FinancialAccount {
            $currency = $this->resolveActiveCurrency($currencyCode);

            $financialAccount = FinancialAccount::query()->create([
                'user_id' => $user->id,
                'name' => $trimmedName,
                'type' => $accountType,
                'currency_code' => $currency->code,
                'institution' => $institution,
                'description' => $description,
                'is_archived' => false,
                'archived_at' => null,
            ]);

            $ledgerAccount = LedgerAccount::query()->create([
                'user_id' => $user->id,
                'financial_account_id' => $financialAccount->id,
                'code' => sprintf('ACCT-%d', $financialAccount->id),
                'name' => $trimmedName,
                'classification' => $accountType->ledgerClassification(),
                'currency_code' => $currency->code,
                'is_system' => false,
            ]);

            $this->auditService->record(
                actor: $user,
                action: AuditService::ACTION_ACCOUNT_CREATED,
                auditableType: FinancialAccount::class,
                auditableId: $financialAccount->id,
                metadata: [
                    'ledger_account_id' => $ledgerAccount->id,
                    'type' => $accountType->value,
                    'currency_code' => $currency->code,
                ],
            );

            return $financialAccount->load(['currency', 'ledgerAccount']);
        });
    }

    /**
     * Updates a user-visible financial account and keeps its linked ledger account synchronized.
     *
     * Acquires row locks in the global lock hierarchy (financial_accounts -> currencies -> ledger_accounts)
     * before checking for posted ledger activity so concurrent postings cannot race with a currency
     * or classification change and cannot deadlock via lock-order inversion.
     *
     * @param  array{
     *     name: string,
     *     type: FinancialAccountType|string,
     *     currency_code?: string,
     *     institution?: string|null,
     *     description?: string|null
     * }  $data
     */
    public function updateAccount(User $actor, FinancialAccount $account, array $data): FinancialAccount
    {
        $this->assertOwnership($actor, $account);

        $accountType = $data['type'] instanceof FinancialAccountType
            ? $data['type']
            : FinancialAccountType::from((string) $data['type']);

        $explicitCurrencyCode = isset($data['currency_code'])
            ? strtoupper(trim($data['currency_code']))
            : null;

        $trimmedName = trim($data['name']);
        $institution = array_key_exists('institution', $data) && is_string($data['institution']) && trim($data['institution']) !== ''
            ? trim($data['institution'])
            : null;
        $description = array_key_exists('description', $data) && is_string($data['description']) && trim($data['description']) !== ''
            ? trim($data['description'])
            : null;

        return DB::transaction(function () use (
            $actor,
            $account,
            $accountType,
            $explicitCurrencyCode,
            $trimmedName,
            $institution,
            $description
        ): FinancialAccount {
            // 1. Lock and reload the financial account first
            /** @var FinancialAccount $lockedAccount */
            $lockedAccount = FinancialAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnership($actor, $lockedAccount);

            // 2. Determine the requested currency using the locked, current account state
            $requestedCurrencyCode = $explicitCurrencyCode ?? $lockedAccount->currency_code;
            $isChangingCurrency = $requestedCurrencyCode !== $lockedAccount->currency_code;

            // 3. When currency is changing, lock and validate the Currency row BEFORE locking LedgerAccount
            // so lock acquisition follows financial_accounts -> currencies -> ledger_accounts
            if ($isChangingCurrency) {
                $this->resolveActiveCurrency($requestedCurrencyCode);
            }

            // 4. Lock the linked LedgerAccount afterward
            /** @var LedgerAccount $ledgerAccount */
            $ledgerAccount = LedgerAccount::query()
                ->where('financial_account_id', $lockedAccount->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasPostedActivity = $ledgerAccount->postings()->exists();

            if ($isChangingCurrency && $hasPostedActivity) {
                throw new ImmutableRecordException(
                    'Financial account currency cannot be changed once ledger activity exists.',
                );
            }

            if (
                $hasPostedActivity
                && $accountType->ledgerClassification() !== $lockedAccount->type->ledgerClassification()
            ) {
                throw new ImmutableRecordException(
                    'Account type cannot switch between asset and liability classifications once ledger activity exists.',
                );
            }

            $lockedAccount->fill([
                'name' => $trimmedName,
                'type' => $accountType,
                'currency_code' => $requestedCurrencyCode,
                'institution' => $institution,
                'description' => $description,
            ]);

            $changedFields = array_keys($lockedAccount->getDirty());
            $lockedAccount->save();

            $ledgerAccount->fill([
                'name' => $trimmedName,
                'classification' => $accountType->ledgerClassification(),
                'currency_code' => $requestedCurrencyCode,
            ]);
            $ledgerAccount->save();

            $this->auditService->record(
                actor: $actor,
                action: AuditService::ACTION_ACCOUNT_UPDATED,
                auditableType: FinancialAccount::class,
                auditableId: $lockedAccount->id,
                metadata: [
                    'changed_fields' => $changedFields,
                    'type' => $accountType->value,
                    'currency_code' => $requestedCurrencyCode,
                ],
            );

            return $lockedAccount->load(['currency', 'ledgerAccount']);
        });
    }

    /**
     * Archives a financial account inside a locked transaction so stale model instances
     * cannot overwrite newer fields or emit duplicate archive audit events.
     */
    public function archiveAccount(User $actor, FinancialAccount $account): FinancialAccount
    {
        $this->assertOwnership($actor, $account);

        return DB::transaction(function () use ($actor, $account): FinancialAccount {
            /** @var FinancialAccount $lockedAccount */
            $lockedAccount = FinancialAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnership($actor, $lockedAccount);

            if ($lockedAccount->is_archived) {
                return $lockedAccount->load(['currency', 'ledgerAccount']);
            }

            $lockedAccount->update([
                'is_archived' => true,
                'archived_at' => now(),
            ]);

            $this->auditService->record(
                actor: $actor,
                action: AuditService::ACTION_ACCOUNT_ARCHIVED,
                auditableType: FinancialAccount::class,
                auditableId: $lockedAccount->id,
                metadata: [
                    'currency_code' => $lockedAccount->currency_code,
                ],
            );

            return $lockedAccount->load(['currency', 'ledgerAccount']);
        });
    }

    /**
     * Restores an archived financial account inside a locked transaction so stale model instances
     * cannot overwrite newer fields or emit duplicate restore audit events.
     */
    public function restoreAccount(User $actor, FinancialAccount $account): FinancialAccount
    {
        $this->assertOwnership($actor, $account);

        return DB::transaction(function () use ($actor, $account): FinancialAccount {
            /** @var FinancialAccount $lockedAccount */
            $lockedAccount = FinancialAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnership($actor, $lockedAccount);

            if (! $lockedAccount->is_archived) {
                return $lockedAccount->load(['currency', 'ledgerAccount']);
            }

            $lockedAccount->update([
                'is_archived' => false,
                'archived_at' => null,
            ]);

            $this->auditService->record(
                actor: $actor,
                action: AuditService::ACTION_ACCOUNT_RESTORED,
                auditableType: FinancialAccount::class,
                auditableId: $lockedAccount->id,
                metadata: [
                    'currency_code' => $lockedAccount->currency_code,
                ],
            );

            return $lockedAccount->load(['currency', 'ledgerAccount']);
        });
    }

    private function resolveActiveCurrency(string $currencyCode): Currency
    {
        $normalizedCode = strtoupper(trim($currencyCode));

        $currency = Currency::query()
            ->where('code', $normalizedCode)
            ->lockForUpdate()
            ->first();

        if (! $currency || ! $currency->is_active) {
            throw new LedgerIntegrityException(sprintf(
                'Currency [%s] is not an active supported currency.',
                $normalizedCode,
            ));
        }

        return $currency;
    }

    private function assertOwnership(User $actor, FinancialAccount $account): void
    {
        if ((int) $actor->id !== (int) $account->user_id) {
            throw new LedgerIntegrityException('Unauthorized access to financial account.');
        }
    }
}
