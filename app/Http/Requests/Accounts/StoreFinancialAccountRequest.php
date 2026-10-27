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
        if ($this->has('currency_code') && is_string($this->input('currency_code'))) {
            $this->merge([
                'currency_code' => strtoupper(trim($this->input('currency_code'))),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency_code.exists' => 'Please select an active supported currency.',
        ];
    }
}
