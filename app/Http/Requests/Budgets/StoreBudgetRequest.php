<?php

namespace App\Http\Requests\Budgets;

use App\Models\Budget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Budget::class) ?? false;
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
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('is_active', true),
            ],
            'year' => ['required', 'integer', 'min:1', 'max:9999'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency_code.exists' => 'Please select an active supported currency.',
            'month.min' => 'Month must be between 1 and 12.',
            'month.max' => 'Month must be between 1 and 12.',
        ];
    }
}
