<?php

declare(strict_types=1);

namespace App\Http\Requests\ItemCatalog;

use App\Core\Enums\ItemBehavior;
use App\Http\Requests\Concerns\ScopesToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by create and update; on update every field is optional and the code's unique rule
 * ignores the item itself.
 */
class StoreItemRequest extends FormRequest
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
        $updating = $this->route('id') !== null;
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:150'],
            // behavior, not type + track_stock: a service with stock cannot even be sent.
            'behavior' => [$required, Rule::enum(ItemBehavior::class)],
            'unit_of_measure_id' => [
                $required, 'integer',
                Rule::exists('units_of_measure', 'id')->where('company_id', $this->companyId()),
            ],
            'item_category_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('item_categories', 'id')->where('company_id', $this->companyId()),
            ],
            // Optional: the company's next ART-/SRV- code when missing (Q1).
            'code' => [
                'sometimes', 'nullable', 'string', 'max:32', 'regex:/^[A-Z0-9_\-]+$/',
                Rule::unique('items', 'code')->where('company_id', $this->companyId())
                    ->ignore($updating ? (int) $this->route('id') : null),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'unit_of_measure_id.exists' => 'La unidad no existe en esta empresa.',
            'item_category_id.exists' => 'La categoría no existe en esta empresa.',
            'code.unique' => 'Ya existe un ítem con ese código.',
            'code.regex' => 'El código sólo admite letras, números, guion y guion bajo.',
        ];
    }
}
