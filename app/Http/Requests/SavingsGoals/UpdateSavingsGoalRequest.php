<?php

namespace App\Http\Requests\SavingsGoals;

use App\Http\Requests\SavingsGoals\Concerns\AuthorizesSavingsGoalRead;
use App\Http\Requests\SavingsGoals\Concerns\RejectsUnknownRequestBodyFields;
use App\Rules\PositiveMinorAmountString;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSavingsGoalRequest extends FormRequest
{
    use AuthorizesSavingsGoalRead;
    use RejectsUnknownRequestBodyFields;

    /**
     * @var list<string>
     */
    private const ALLOWED_BODY_FIELDS = [
        'name',
        'description',
        'target_amount_minor',
        'target_date',
    ];

    public function authorize(): bool
    {
        return $this->authorizeOwnedSavingsGoalAbility('update');
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

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
            'name' => ['required', 'string', 'max:120'],
            'target_amount_minor' => ['required', new PositiveMinorAmountString],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'target_date' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/\A\d{4}-\d{2}-\d{2}\z/',
                'date_format:Y-m-d',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->rejectUnknownBodyFields($validator, self::ALLOWED_BODY_FIELDS);
        });
    }
}
