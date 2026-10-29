<?php

namespace App\Http\Requests\Budgets;

use App\Models\Budget;
use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBudgetAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Budget|null $budget */
        $budget = $this->route('budget');

        if (! $budget instanceof Budget) {
            return false;
        }

        return $this->user()?->can('update', $budget) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('amount') && is_string($this->input('amount'))) {
            $merged['amount'] = trim($this->input('amount'));
        }

        $category = $this->route('category');
        if ($category instanceof Category) {
            $merged['category_id'] = (int) $category->id;
        } elseif (is_numeric($category)) {
            $merged['category_id'] = (int) $category;
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
            'amount' => ['required', 'string', 'max:64'],
            // Cross-tenant category IDs fail validation (422) without a 403 that confirms existence.
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where('user_id', $userId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category is invalid.',
        ];
    }
}
