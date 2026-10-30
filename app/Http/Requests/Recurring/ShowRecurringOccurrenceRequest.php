<?php

namespace App\Http\Requests\Recurring;

use App\Models\RecurringOccurrence;
use Illuminate\Foundation\Http\FormRequest;

class ShowRecurringOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var RecurringOccurrence|null $occurrence */
        $occurrence = $this->route('occurrence');

        if (! $occurrence instanceof RecurringOccurrence) {
            return false;
        }

        return $this->user()?->can('view', $occurrence) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
