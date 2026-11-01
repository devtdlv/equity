<?php

namespace App\Http\Requests\SavingsGoals;

use App\Models\SavingsGoal;
use Illuminate\Foundation\Http\FormRequest;

class ShowSavingsGoalPortfolioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', SavingsGoal::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
