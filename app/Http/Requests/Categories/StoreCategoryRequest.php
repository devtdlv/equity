<?php

namespace App\Http\Requests\Categories;

use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Category::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('name') && is_string($this->input('name'))) {
            $merged['name'] = trim($this->input('name'));
        }

        if ($this->has('type') && is_string($this->input('type'))) {
            $merged['type'] = strtolower(trim($this->input('type')));
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
        $type = is_string($this->input('type')) ? $this->input('type') : '';

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('categories', 'name')
                    ->where(fn ($query) => $query
                        ->where('user_id', $userId)
                        ->where('type', $type)),
            ],
            'type' => ['required', 'string', Rule::enum(CategoryType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A category with this name and type already exists.',
        ];
    }
}
