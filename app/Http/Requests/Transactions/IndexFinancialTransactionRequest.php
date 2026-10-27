<?php

namespace App\Http\Requests\Transactions;

use App\Enums\FinancialTransactionType;
use App\Models\FinancialTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexFinancialTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', FinancialTransaction::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('type') && is_string($this->input('type'))) {
            $merged['type'] = strtolower(trim($this->input('type')));
        }

        if ($this->has('start_date') && is_string($this->input('start_date'))) {
            $merged['start_date'] = trim($this->input('start_date'));
        }

        if ($this->has('end_date') && is_string($this->input('end_date'))) {
            $merged['end_date'] = trim($this->input('end_date'));
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
            'type' => ['nullable', 'string', Rule::enum(FinancialTransactionType::class)],
            'financial_account_id' => [
                'nullable',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('user_id', $userId),
            ],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('user_id', $userId),
            ],
            'start_date' => ['nullable', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}\z/', 'date_format:Y-m-d'],
            'end_date' => [
                'nullable',
                'string',
                'regex:/\A\d{4}-\d{2}-\d{2}\z/',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'financial_account_id.exists' => 'The selected financial account is invalid.',
            'category_id.exists' => 'The selected category is invalid.',
        ];
    }
}
