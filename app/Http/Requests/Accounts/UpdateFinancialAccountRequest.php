<?php

namespace App\Http\Requests\Accounts;

use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var FinancialAccount|null $account */
        $account = $this->route('account');

        return $account !== null && ($this->user()?->can('update', $account) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency_code') && is_string($this->input('currency_code'))) {
            $this->merge([
                'currency_code' => strtoupper(trim($this->input('currency_code'))),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::enum(FinancialAccountType::class)],
            'currency_code' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('is_active', true),
            ],
            'institution' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var FinancialAccount|null $account */
                $account = $this->route('account');

                if ($account === null || ! $account->hasPostedActivity()) {
                    return;
                }

                $requestedCurrency = $this->input('currency_code');
                if (is_string($requestedCurrency) && $requestedCurrency !== $account->currency_code) {
                    $validator->errors()->add(
                        'currency_code',
                        'Account currency cannot be changed once ledger activity exists.',
                    );
                }

                $requestedType = FinancialAccountType::tryFrom((string) $this->input('type'));
                if (
                    $requestedType !== null
                    && $requestedType->ledgerClassification() !== $account->type->ledgerClassification()
                ) {
                    $validator->errors()->add(
                        'type',
                        'Account type cannot switch between asset and liability classifications once ledger activity exists.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency_code.exists' => 'Please select an active supported currency.',
        ];
    }
}
