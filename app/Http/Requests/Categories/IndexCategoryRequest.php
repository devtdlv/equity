<?php

namespace App\Http\Requests\Categories;

use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Category::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('type') && is_string($this->input('type'))) {
            $this->merge([
                'type' => strtolower(trim($this->input('type'))),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::enum(CategoryType::class)],
            'include_archived' => ['nullable', 'boolean'],
        ];
    }
}
