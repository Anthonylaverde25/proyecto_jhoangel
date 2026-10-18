<?php

declare(strict_types=1);

namespace App\Http\Requests\ItemCatalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by create and update; on update both fields are optional.
 */
class StoreItemPresentationRequest extends FormRequest
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
        $required = $this->route('presentationId') !== null ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:64'],
            'factor_to_item_unit' => [$required, 'numeric', 'gt:0', 'max:99999999999999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['factor_to_item_unit.gt' => 'La presentación tiene que contener más de cero.'];
    }
}
