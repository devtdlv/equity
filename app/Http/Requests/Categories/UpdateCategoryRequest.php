<?php

namespace App\Http\Requests\Categories;

use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Category|null $category */
        $category = $this->route('category');

        return $category !== null && ($this->user()?->can('update', $category) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge([
                'name' => trim($this->input('name')),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Category|null $category */
        $category = $this->route('category');
        $userId = (int) $this->user()?->id;
        $type = $category?->type->value ?? '';

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('categories', 'name')
                    ->where(fn ($query) => $query
                        ->where('user_id', $userId)
                        ->where('type', $type))
                    ->ignore($category?->id),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Category|null $category */
                $category = $this->route('category');

                if ($category !== null && $this->has('type')) {
                    $requestedType = is_string($this->input('type'))
                        ? strtolower(trim($this->input('type')))
                        : $this->input('type');

                    if ($requestedType !== $category->type->value) {
                        $validator->errors()->add(
                            'type',
                            'Category type cannot be changed after creation.',
                        );
                    }
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
            'name.unique' => 'A category with this name and type already exists.',
        ];
    }
}
