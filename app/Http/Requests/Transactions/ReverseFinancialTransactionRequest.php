<?php

namespace App\Http\Requests\Transactions;

use App\Models\FinancialTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReverseFinancialTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $transaction instanceof FinancialTransaction
            && ($this->user()?->can('reverse', $transaction) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('idempotency_key') && is_string($this->input('idempotency_key'))) {
            $merged['idempotency_key'] = strtolower(trim($this->input('idempotency_key')));
        }

        if ($this->has('reason') && is_string($this->input('reason'))) {
            $merged['reason'] = trim($this->input('reason'));
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reversal reason is required.',
            'reason.min' => 'A reversal reason is required.',
            'idempotency_key.uuid' => 'The idempotency key must be a valid UUID.',
        ];
    }
}
