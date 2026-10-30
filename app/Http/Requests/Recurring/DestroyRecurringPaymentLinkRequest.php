<?php

namespace App\Http\Requests\Recurring;

use App\Models\RecurringOccurrence;
use App\Models\RecurringPaymentLink;
use Illuminate\Foundation\Http\FormRequest;

class DestroyRecurringPaymentLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var RecurringOccurrence|null $occurrence */
        $occurrence = $this->route('occurrence');
        /** @var RecurringPaymentLink|null $link */
        $link = $this->route('link');

        if (! $occurrence instanceof RecurringOccurrence || ! $link instanceof RecurringPaymentLink) {
            return false;
        }

        if ((int) $link->occurrence_id !== (int) $occurrence->id) {
            return false;
        }

        $user = $this->user();

        return ($user?->can('unlinkPayment', $occurrence) ?? false)
            && ($user?->can('delete', $link) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
