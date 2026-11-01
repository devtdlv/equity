<?php

namespace App\Http\Requests\SavingsGoals;

use App\Http\Requests\SavingsGoals\Concerns\AuthorizesSavingsGoalRead;
use App\Http\Requests\SavingsGoals\Concerns\RejectsUnknownRequestBodyFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ArchiveSavingsGoalRequest extends FormRequest
{
    use AuthorizesSavingsGoalRead;
    use RejectsUnknownRequestBodyFields;

    public function authorize(): bool
    {
        return $this->authorizeOwnedSavingsGoalAbility('archive');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->rejectUnknownBodyFields($validator, []);
        });
    }
}
