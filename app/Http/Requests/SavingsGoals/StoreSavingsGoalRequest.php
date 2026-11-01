<?php

namespace App\Http\Requests\SavingsGoals;

use App\Http\Requests\SavingsGoals\Concerns\RejectsUnknownRequestBodyFields;
use App\Models\SavingsGoal;
use App\Rules\PositiveMinorAmountString;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSavingsGoalRequest extends FormRequest
{
    use RejectsUnknownRequestBodyFields;

    /**
     * @var list<string>
     */
    private const ALLOWED_BODY_FIELDS = [
        'idempotency_key',
        'name',
        'description',
        'currency_code',
        'target_amount_minor',
        'target_date',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('create', SavingsGoal::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('idempotency_key') && is_string($this->input('idempotency_key'))) {
            $merged['idempotency_key'] = strtolower(trim($this->input('idempotency_key')));
        }

        if ($this->has('currency_code') && is_string($this->input('currency_code'))) {
            $merged['currency_code'] = strtoupper(trim($this->input('currency_code')));
        }

        if ($this->has('name') && is_string($this->input('name'))) {
            $merged['name'] = trim($this->input('name'));
        }

        if ($this->has('target_date') && is_string($this->input('target_date'))) {
            $merged['target_date'] = trim($this->input('target_date'));
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
            'description' => ['nullable', 'string', 'max:500'],
            'currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('is_active', true),
            ],
            'target_amount_minor' => ['required', new PositiveMinorAmountString],
            'target_date' => ['nullable', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}\z/', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->rejectUnknownBodyFields($validator, self::ALLOWED_BODY_FIELDS);
        });
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
