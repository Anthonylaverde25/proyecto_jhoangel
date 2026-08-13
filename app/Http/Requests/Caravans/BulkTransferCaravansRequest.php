<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class BulkTransferCaravansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'caravan_ids'     => 'required|array|min:1',
            'caravan_ids.*'   => 'required|integer|exists:caravans,id',
            'target_batch_id' => 'nullable|integer|exists:batches,id',
            'reason'          => 'nullable|string|max:500',
            'movement_date'   => 'nullable|date',
        ];
    }
}
