<?php

namespace App\Http\Requests\Recurring;

use App\Models\RecurringObligation;
use Illuminate\Foundation\Http\FormRequest;

class ShowRecurringObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var RecurringObligation|null $obligation */
        $obligation = $this->route('obligation');

        if (! $obligation instanceof RecurringObligation) {
            return false;
        }

        return $this->user()?->can('view', $obligation) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
