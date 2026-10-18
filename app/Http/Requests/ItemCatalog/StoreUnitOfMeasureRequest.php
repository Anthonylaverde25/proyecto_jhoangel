<?php

declare(strict_types=1);

namespace App\Http\Requests\ItemCatalog;

use App\Core\Enums\UnitDimension;
use App\Http\Requests\Concerns\ScopesToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitOfMeasureRequest extends FormRequest
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
            'code' => [
                'required', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('units_of_measure', 'code')->where('company_id', $this->companyId()),
            ],
            'name' => ['required', 'string', 'max:64'],
            'symbol' => ['required', 'string', 'max:16'],
            'dimension' => ['required', Rule::enum(UnitDimension::class)],
            // Null = not convertible (dose, head, workday).
            'factor_to_base' => ['present', 'nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'display_order' => ['sometimes', 'integer', 'between:1,65535'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe una unidad con ese código.',
            'code.regex' => 'El código sólo admite letras, números y guion bajo.',
            'factor_to_base.gt' => 'El factor tiene que ser mayor que cero.',
        ];
    }
}
