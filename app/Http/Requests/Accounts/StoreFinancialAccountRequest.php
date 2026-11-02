<?php

namespace App\Http\Requests\Accounts;

use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FinancialAccount::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('currency_code') && is_string($this->input('currency_code'))) {
            $merged['currency_code'] = strtoupper(trim($this->input('currency_code')));
        }

        if ($this->has('idempotency_key') && is_string($this->input('idempotency_key'))) {
            $merged['idempotency_key'] = strtolower(trim($this->input('idempotency_key')));
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
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::enum(FinancialAccountType::class)],
            'currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('is_active', true),
            ],
            'institution' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'starting_balance' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency_code.exists' => 'Please select an active supported currency.',
            'idempotency_key.uuid' => 'The idempotency key must be a valid UUID.',
        ];
    }
}
