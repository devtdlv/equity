<?php

namespace App\Http\Requests\Transactions;

use App\Enums\FinancialTransactionType;
use App\Models\FinancialTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FinancialTransaction::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('type') && is_string($this->input('type'))) {
            $merged['type'] = strtolower(trim($this->input('type')));
        }

        if ($this->has('idempotency_key') && is_string($this->input('idempotency_key'))) {
            $merged['idempotency_key'] = strtolower(trim($this->input('idempotency_key')));
        }

        if ($this->has('entry_date') && is_string($this->input('entry_date'))) {
            $merged['entry_date'] = trim($this->input('entry_date'));
        }

        if ($this->has('description') && is_string($this->input('description'))) {
            $merged['description'] = trim($this->input('description'));
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
        $type = is_string($this->input('type')) ? $this->input('type') : '';
        $isTransfer = $type === FinancialTransactionType::Transfer->value;

        return [
            'type' => ['required', 'string', Rule::enum(FinancialTransactionType::class)],
            'financial_account_id' => [
                'required',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('user_id', $userId),
            ],
            'transfer_financial_account_id' => $isTransfer
                ? [
                    'required',
                    'integer',
                    'different:financial_account_id',
                    Rule::exists('financial_accounts', 'id')->where('user_id', $userId),
                ]
                : ['nullable', 'prohibited'],
            'category_id' => $isTransfer
                ? ['nullable', 'prohibited']
                : [
                    'required',
                    'integer',
                    Rule::exists('categories', 'id')->where('user_id', $userId),
                ],
            'amount' => ['required', 'string', 'max:64'],
            'entry_date' => ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}\z/', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'financial_account_id.exists' => 'The selected financial account is invalid.',
            'transfer_financial_account_id.exists' => 'The selected transfer destination account is invalid.',
            'transfer_financial_account_id.different' => 'Transfer source and destination financial accounts must be distinct.',
            'transfer_financial_account_id.prohibited' => 'Income and expense transactions cannot specify a transfer destination account.',
            'category_id.exists' => 'The selected category is invalid.',
            'category_id.prohibited' => 'Transfer transactions cannot specify a category.',
        ];
    }
}
