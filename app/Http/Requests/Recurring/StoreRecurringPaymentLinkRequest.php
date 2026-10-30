<?php

namespace App\Http\Requests\Recurring;

use App\Models\RecurringOccurrence;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecurringPaymentLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var RecurringOccurrence|null $occurrence */
        $occurrence = $this->route('occurrence');

        if (! $occurrence instanceof RecurringOccurrence) {
            return false;
        }

        return $this->user()?->can('linkPayment', $occurrence) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = (int) $this->user()?->id;

        return [
            'financial_transaction_id' => [
                'required',
                'integer',
                Rule::exists('financial_transactions', 'id')->where('user_id', $userId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'financial_transaction_id.exists' => 'The selected financial transaction is invalid.',
        ];
    }
}
