<?php

namespace App\Http\Requests\Recurring;

use App\Enums\RecurringOccurrenceStatus;
use App\Models\RecurringOccurrence;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRecurringOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', RecurringOccurrence::class) ?? false;
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

        if ($this->has('from_date') && is_string($this->input('from_date'))) {
            $merged['from_date'] = trim($this->input('from_date'));
        }

        if ($this->has('to_date') && is_string($this->input('to_date'))) {
            $merged['to_date'] = trim($this->input('to_date'));
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
            'from_date' => ['nullable', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}\z/', 'date_format:Y-m-d'],
            'to_date' => [
                'nullable',
                'string',
                'regex:/\A\d{4}-\d{2}-\d{2}\z/',
                'date_format:Y-m-d',
                'after_or_equal:from_date',
            ],
            'currency_code' => [
                'nullable',
                'string',
                'size:3',
                Rule::exists('currencies', 'code'),
            ],
            'obligation_id' => [
                'nullable',
                'integer',
                Rule::exists('recurring_obligations', 'id')->where('user_id', $userId),
            ],
            'status' => ['nullable', 'string', Rule::enum(RecurringOccurrenceStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
