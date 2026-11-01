<?php

namespace App\Http\Requests\SavingsGoals;

use App\Enums\SavingsGoalStatus;
use App\Models\SavingsGoal;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSavingsGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', SavingsGoal::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('status') && is_string($this->input('status'))) {
            $this->merge([
                'status' => strtolower(trim($this->input('status'))),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(SavingsGoalStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
