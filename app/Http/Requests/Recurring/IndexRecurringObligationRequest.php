<?php

namespace App\Http\Requests\Recurring;

use App\Enums\RecurringObligationStatus;
use App\Models\RecurringObligation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRecurringObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', RecurringObligation::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('currency_code') && is_string($this->input('currency_code'))) {
            $merged['currency_code'] = strtoupper(trim($this->input('currency_code')));
        }

        if ($this->has('status') && is_string($this->input('status'))) {
            $merged['status'] = strtolower(trim($this->input('status')));
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
            'currency_code' => [
                'nullable',
                'string',
                'size:3',
                Rule::exists('currencies', 'code'),
            ],
            'status' => ['nullable', 'string', Rule::enum(RecurringObligationStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
