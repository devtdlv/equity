<?php

namespace App\Http\Requests\SavingsGoals\Concerns;

use App\Models\SavingsGoal;

/**
 * Shared owner-scoped authorization for savings goal detail, events, and mutations.
 *
 * Missing and foreign-owned numeric goal IDs both fail closed as unauthorized (HTTP 403).
 * Syntactically invalid route IDs are typically rejected earlier by whereNumber.
 */
trait AuthorizesSavingsGoalRead
{
    protected function authorizeOwnedSavingsGoalRead(): bool
    {
        return $this->authorizeOwnedSavingsGoalAbility('view');
    }

    protected function authorizeOwnedSavingsGoalAbility(string $ability): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('viewAny', SavingsGoal::class)) {
            return false;
        }

        $goalId = $this->resolvePositiveRouteGoalId();

        if ($goalId === null) {
            return false;
        }

        $goal = SavingsGoal::query()->whereKey($goalId)->first();

        if ($goal === null) {
            return false;
        }

        return $user->can($ability, $goal);
    }

    /**
     * Parse route {goal} as a positive PHP int ID without overflow coercion.
     */
    protected function resolvePositiveRouteGoalId(): ?int
    {
        $raw = $this->route('goal');

        if (is_int($raw)) {
            return $raw >= 1 ? $raw : null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (! preg_match('/\A[1-9]\d*\z/', $raw)) {
            return null;
        }

        $maxDigits = (string) PHP_INT_MAX;

        if (strlen($raw) > strlen($maxDigits)) {
            return null;
        }

        if (strlen($raw) === strlen($maxDigits) && $raw > $maxDigits) {
            return null;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => PHP_INT_MAX,
            ],
        ]);

        return $parsed === false ? null : $parsed;
    }
}
