<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

class SetCriticalStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Empty clears the control (D19).
            'critical_stock' => ['present', 'nullable', 'decimal:0,4', 'min:0'],
        ];
    }
}
