<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Dar de baja pendientes": how many head received without caravan, of which DTE and batch, will
 * never get one, and why.
 */
final class WriteOffPendingHeadsRequest extends FormRequest
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
            'dte_id' => 'required|integer',
            'batch_id' => 'required|integer',
            'head_count' => 'required|integer|min:1|max:5000',
            'reason' => 'required|string|max:1000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'head_count.min' => 'Indicá cuántas cabezas se dan de baja.',
            'reason.required' => 'Indicá por qué se dan de baja (por ejemplo, murió antes de caravanearse).',
        ];
    }
}
