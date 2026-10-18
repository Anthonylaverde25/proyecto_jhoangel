<?php

declare(strict_types=1);

namespace App\Http\Requests\ItemCatalog;

use App\Http\Requests\Concerns\ScopesToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemCategoryRequest extends FormRequest
{
    use ScopesToActiveCompany;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeCode();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('item_categories', 'id')->where('company_id', $this->companyId())],
            // Optional: derived from the name when missing (C1).
            'code' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('item_categories', 'code')->where('company_id', $this->companyId()),
            ],
            'display_order' => ['sometimes', 'integer', 'between:1,65535'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'La categoría padre no existe en esta empresa.',
            'code.unique' => 'Ya existe una categoría con ese código.',
        ];
    }
}
