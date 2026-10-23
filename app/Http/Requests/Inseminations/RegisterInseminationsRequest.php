<?php

declare(strict_types=1);

namespace App\Http\Requests\Inseminations;

use Illuminate\Foundation\Http\FormRequest;

/** Shape of the insemination sheet; the business rules answer per cell from the use case (IA6-IA8). */
class RegisterInseminationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => 'required|string|max:80',
            'rows' => 'required|array|min:1|max:500',
            'rows.*.female_caravan_id' => 'required|integer',
            'rows.*.inseminated_on' => 'required|date_format:Y-m-d',
            'rows.*.item_id' => 'required|integer',
            'rows.*.semen_lot_id' => 'nullable|integer',
            'rows.*.straws_used' => 'required|integer|min:1|max:20',
            'rows.*.warehouse_id' => 'required|integer',
            'rows.*.notes' => 'nullable|string|max:255',
        ];
    }
}
