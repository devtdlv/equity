<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('name') && is_string($this->input('name'))) {
            $merged['name'] = trim($this->input('name'));
        }

        if ($this->has('email') && is_string($this->input('email'))) {
            $merged['email'] = Str::lower(trim($this->input('email')));
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $emailChanging = $this->emailIsChanging($user);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
            'current_password' => $emailChanging
                ? ['required', 'string', 'current_password']
                : ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Unable to update your profile with the provided email address.',
            'current_password.required' => 'Please confirm your current password to change your email address.',
            'current_password.current_password' => 'The provided password does not match your current password.',
        ];
    }

    public function emailIsChanging(?User $user = null): bool
    {
        $user ??= $this->user();

        if ($user === null) {
            return false;
        }

        $incoming = Str::lower(trim((string) $this->input('email')));

        return $incoming !== '' && $incoming !== Str::lower((string) $user->email);
    }
}
