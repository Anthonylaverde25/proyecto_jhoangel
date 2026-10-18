<?php

declare(strict_types=1);

namespace App\Http\Requests\ItemCatalog;

use App\Http\Requests\Concerns\ScopesToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemCategoryRequest extends FormRequest
{
    use ScopesToActiveCompany;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('item_categories', 'id')->where('company_id', $this->companyId())],
            'display_order' => ['sometimes', 'integer', 'between:1,65535'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['parent_id.exists' => 'La categoría padre no existe en esta empresa.'];
    }
}
