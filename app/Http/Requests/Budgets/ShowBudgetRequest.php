<?php

namespace App\Http\Requests\Budgets;

use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;

class ShowBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Budget|null $budget */
        $budget = $this->route('budget');

        if (! $budget instanceof Budget) {
            return false;
        }

        return $this->user()?->can('view', $budget) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
