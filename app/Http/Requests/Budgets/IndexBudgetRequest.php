<?php

namespace App\Http\Requests\Budgets;

use App\Models\Budget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Budget::class) ?? false;
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
            'currency_code' => [
                'nullable',
                'string',
                'size:3',
                Rule::exists('currencies', 'code'),
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
            'currency_code.exists' => 'The selected currency is invalid.',
        ];
    }
}
