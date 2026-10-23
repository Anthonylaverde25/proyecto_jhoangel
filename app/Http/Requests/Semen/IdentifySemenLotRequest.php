<?php

declare(strict_types=1);

namespace App\Http\Requests\Semen;

use Illuminate\Foundation\Http\FormRequest;

class IdentifySemenLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lots' => ['present', 'array', 'max:20'],
            'lots.*.semen_lot_id' => ['nullable', 'integer'],
            'lots.*.lot_code' => ['nullable', 'string', 'max:60'],
            'lots.*.collection_date' => ['nullable', 'date'],
            'lots.*.quantity' => ['nullable'],
        ];
    }
}
