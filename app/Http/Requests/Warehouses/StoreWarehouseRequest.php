<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehouses;

use App\Core\Enums\WarehouseKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Code, name and kind of a warehouse (DD1, S-0). Without a kind, a new one is general and an edit
 * keeps the one it has. Their format and uniqueness are domain rules (R1), so the
 * screen gets them per field in Spanish.
 */
class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(WarehouseKind::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'Indicá el código.',
            'code.max' => 'El código tiene hasta 32 caracteres.',
            'name.required' => 'Indicá el nombre.',
            'name.max' => 'El nombre tiene hasta 100 caracteres.',
            'kind.enum' => 'El tipo es almacén general o termo.',
        ];
    }

    public function kind(): ?WarehouseKind
    {
        $kind = $this->validated('kind');

        return $kind === null ? null : WarehouseKind::from((string) $kind);
    }
}
