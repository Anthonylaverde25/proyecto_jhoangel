<?php

declare(strict_types=1);

namespace App\Http\Requests\ItemCatalog;

use App\Core\Enums\UnitDimension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The code never changes: it is the key the copy between companies compares.
 */
class UpdateUnitOfMeasureRequest extends FormRequest
{
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
            'name' => ['sometimes', 'string', 'max:64'],
            'symbol' => ['sometimes', 'string', 'max:16'],
            'dimension' => ['sometimes', Rule::enum(UnitDimension::class)],
            'factor_to_base' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'display_order' => ['sometimes', 'integer', 'between:1,65535'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['factor_to_base.gt' => 'El factor tiene que ser mayor que cero.'];
    }
}
