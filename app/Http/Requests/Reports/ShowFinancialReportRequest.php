<?php

namespace App\Http\Requests\Reports;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ShowFinancialReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('mode') && is_string($this->input('mode'))) {
            $merged['mode'] = strtolower(trim($this->input('mode')));
        }

        if ($this->has('month') && is_string($this->input('month'))) {
            $merged['month'] = trim($this->input('month'));
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
            'mode' => ['nullable', 'string', Rule::in(['month', 'custom'])],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'financial_account_id' => [
                'nullable',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('user_id', $userId),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $mode = (string) ($this->input('mode') ?? 'month');

            if ($mode === 'custom') {
                $start = $this->input('start_date');
                $end = $this->input('end_date');

                if (! is_string($start) || $start === '') {
                    $validator->errors()->add('start_date', 'Start date is required for a custom range.');
                }

                if (! is_string($end) || $end === '') {
                    $validator->errors()->add('end_date', 'End date is required for a custom range.');
                }

                if (is_string($start) && is_string($end) && $start !== '' && $end !== '' && $start > $end) {
                    $validator->errors()->add('end_date', 'End date must be on or after the start date.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'month.regex' => 'Month must use YYYY-MM format.',
            'mode.in' => 'Report mode must be month or custom.',
            'financial_account_id.exists' => 'The selected financial account is invalid.',
        ];
    }
}
