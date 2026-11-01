<?php

namespace App\Http\Requests\SavingsGoals;

use App\Http\Requests\SavingsGoals\Concerns\AuthorizesSavingsGoalRead;
use Illuminate\Foundation\Http\FormRequest;

class ShowSavingsGoalRequest extends FormRequest
{
    use AuthorizesSavingsGoalRead;

    /**
     * Missing and foreign-owned goals both fail authorization so the outward
     * HTTP status is 403 in either case (no existence leak via 404).
     */
    public function authorize(): bool
    {
        return $this->authorizeOwnedSavingsGoalRead();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
