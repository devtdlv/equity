<?php

namespace App\Http\Requests\Recurring;

use App\Models\RecurringObligation;
use Illuminate\Foundation\Http\FormRequest;

class ResumeRecurringObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var RecurringObligation|null $obligation */
        $obligation = $this->route('obligation');

        if (! $obligation instanceof RecurringObligation) {
            return false;
        }

        return $this->user()?->can('resume', $obligation) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
