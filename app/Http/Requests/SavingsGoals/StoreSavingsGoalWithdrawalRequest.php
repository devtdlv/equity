<?php

namespace App\Http\Requests\SavingsGoals;

use App\Http\Requests\SavingsGoals\Concerns\AuthorizesSavingsGoalRead;
use App\Http\Requests\SavingsGoals\Concerns\RejectsUnknownRequestBodyFields;
use App\Rules\PositiveMinorAmountString;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSavingsGoalWithdrawalRequest extends FormRequest
{
    use AuthorizesSavingsGoalRead;
    use RejectsUnknownRequestBodyFields;

    /**
     * @var list<string>
     */
    private const ALLOWED_BODY_FIELDS = [
        'idempotency_key',
        'amount_minor',
        'note',
    ];

    public function authorize(): bool
    {
        return $this->authorizeOwnedSavingsGoalAbility('withdraw');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('idempotency_key') && is_string($this->input('idempotency_key'))) {
            $this->merge([
                'idempotency_key' => strtolower(trim($this->input('idempotency_key'))),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'uuid'],
            'amount_minor' => ['required', new PositiveMinorAmountString],
            'note' => ['nullable', 'string', 'max:500'],
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
            'idempotency_key.uuid' => 'The idempotency key must be a valid UUID.',
        ];
    }
}
