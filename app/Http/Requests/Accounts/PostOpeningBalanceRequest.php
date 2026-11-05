<?php

namespace App\Http\Requests\Accounts;

use App\Models\FinancialAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PostOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        if (! $account instanceof FinancialAccount) {
            return false;
        }

        return $this->user()?->can('postOpeningBalance', $account) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'opening_balance' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_balance.required' => 'Enter a non-zero opening balance amount.',
        ];
    }
}
