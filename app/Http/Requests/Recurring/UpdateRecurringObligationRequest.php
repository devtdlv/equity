<?php

namespace App\Http\Requests\Recurring;

use App\Enums\RecurringFrequency;
use App\Models\RecurringObligation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecurringObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var RecurringObligation|null $obligation */
        $obligation = $this->route('obligation');

        if (! $obligation instanceof RecurringObligation) {
            return false;
        }

        return $this->user()?->can('update', $obligation) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('frequency') && is_string($this->input('frequency'))) {
            $merged['frequency'] = strtolower(trim($this->input('frequency')));
        }

        if ($this->has('name') && is_string($this->input('name'))) {
            $merged['name'] = trim($this->input('name'));
        }

        if ($this->has('start_date') && is_string($this->input('start_date'))) {
            $merged['start_date'] = trim($this->input('start_date'));
        }

        if ($this->has('ends_of_month')) {
            $merged['ends_of_month'] = filter_var(
                $this->input('ends_of_month'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? false;
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
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'amount' => ['required', 'string', 'max:64'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where('user_id', $userId),
            ],
            'financial_account_id' => [
                'nullable',
                'integer',
                Rule::exists('financial_accounts', 'id')->where('user_id', $userId),
            ],
            'start_date' => ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}\z/', 'date_format:Y-m-d'],
            'frequency' => ['required', 'string', Rule::enum(RecurringFrequency::class)],
            'anchor_day' => ['required', 'integer', 'min:1', 'max:31'],
            'ends_of_month' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category is invalid.',
            'financial_account_id.exists' => 'The selected financial account is invalid.',
        ];
    }
}
