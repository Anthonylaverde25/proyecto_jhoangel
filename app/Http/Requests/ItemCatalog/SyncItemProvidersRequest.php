<?php

declare(strict_types=1);

namespace App\Http\Requests\ItemCatalog;

use App\Http\Requests\Concerns\ScopesToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The full list of the item's providers (DB4); an empty list unlinks them all.
 */
class SyncItemProvidersRequest extends FormRequest
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
            'providers' => ['present', 'array'],
            'providers.*.provider_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('providers', 'id')->where('company_id', $this->companyId()),
            ],
            'providers.*.lead_time_days' => ['nullable', 'integer', 'between:0,365'],
            'providers.*.is_preferred' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'providers.*.provider_id.exists' => 'El proveedor no existe en esta empresa.',
            'providers.*.provider_id.distinct' => 'El proveedor está dos veces.',
        ];
    }
}
