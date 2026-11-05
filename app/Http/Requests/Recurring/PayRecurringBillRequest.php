<?php

namespace App\Http\Requests\Recurring;

use App\Models\RecurringOccurrence;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayRecurringBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var RecurringOccurrence|null $occurrence */
        $occurrence = $this->route('occurrence');

        if (! $occurrence instanceof RecurringOccurrence) {
            return false;
        }

        return $this->user()?->can('payBill', $occurrence) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('idempotency_key') && is_string($this->input('idempotency_key'))) {
            $merged['idempotency_key'] = strtolower(trim($this->input('idempotency_key')));
        }

        if ($this->has('amount') && is_string($this->input('amount'))) {
            $merged['amount'] = trim($this->input('amount'));
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
        $userId = (int) $this->user()?->id;

        return [
            'idempotency_key' => ['required', 'string', 'uuid'],
            'financial_account_id' => [
                'required',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('user_id', $userId),
            ],
            'amount' => ['required', 'string', 'max:64'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.uuid' => 'The idempotency key must be a valid UUID.',
            'financial_account_id.exists' => 'The selected financial account is invalid.',
            'entry_date.date_format' => 'Entry date must be a valid YYYY-MM-DD date.',
        ];
    }
}
