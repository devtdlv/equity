<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;

class AuditService
{
    public const ACTION_ACCOUNT_CREATED = 'account.created';

    public const ACTION_ACCOUNT_UPDATED = 'account.updated';

    public const ACTION_ACCOUNT_ARCHIVED = 'account.archived';

    public const ACTION_ACCOUNT_RESTORED = 'account.restored';

    public const ACTION_CATEGORY_CREATED = 'category.created';

    public const ACTION_CATEGORY_UPDATED = 'category.updated';

    public const ACTION_CATEGORY_ARCHIVED = 'category.archived';

    public const ACTION_CATEGORY_RESTORED = 'category.restored';

    public const ACTION_JOURNAL_ENTRY_POSTED = 'journal_entry.posted';

    public const ACTION_TRANSACTION_RECORDED = 'transaction.recorded';

    public const ACTION_TRANSACTION_REVERSED = 'transaction.reversed';

    public const ACTION_BUDGET_CREATED = 'budget.created';

    public const ACTION_BUDGET_ALLOCATION_SET = 'budget.allocation_set';

    public const ACTION_RECURRING_OBLIGATION_CREATED = 'recurring_obligation.created';

    public const ACTION_RECURRING_OBLIGATION_UPDATED = 'recurring_obligation.updated';

    public const ACTION_RECURRING_OBLIGATION_PAUSED = 'recurring_obligation.paused';

    public const ACTION_RECURRING_OBLIGATION_RESUMED = 'recurring_obligation.resumed';

    public const ACTION_RECURRING_OBLIGATION_CANCELLED = 'recurring_obligation.cancelled';

    public const ACTION_RECURRING_OCCURRENCE_SKIPPED = 'recurring_occurrence.skipped';

    public const ACTION_RECURRING_PAYMENT_LINKED = 'recurring_payment.linked';

    public const ACTION_RECURRING_PAYMENT_UNLINKED = 'recurring_payment.unlinked';

    /**
     * Sensitive keys that must never be persisted into audit metadata.
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'remember_token',
        'secret',
        'client_secret',
        'api_key',
        'credentials',
    ];

    /**
     * Record an append-only audit event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        User $actor,
        string $action,
        string $auditableType,
        int $auditableId,
        array $metadata = [],
    ): AuditEvent {
        return AuditEvent::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'metadata' => $this->sanitizeMetadata($metadata),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->sanitizeMetadata($value)
                : $value;
        }

        return $sanitized;
    }
}
