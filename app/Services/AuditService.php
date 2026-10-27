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

    public const ACTION_JOURNAL_ENTRY_POSTED = 'journal_entry.posted';

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
