<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehouses;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deactivating the default warehouse names the next one (DD3).
 */
class SetWarehouseActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'new_default_id' => ['nullable', 'integer'],
        ];
    }
}
