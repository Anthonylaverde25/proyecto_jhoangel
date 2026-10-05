<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A reception: by hand on one DTE (`dte_id`, caravans received and caravans that will not arrive)
 * or at the chute (caravans read, by id or by number). Whether each caravan can be received is
 * decided by EntryOrderReceptionService, which reports every problem by row.
 */
final class ReceiveEntryOrderRequest extends FormRequest
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
            'method' => 'required|string|in:MANUAL,CHUTE,SHEET,manual,chute,sheet',
            'received_at' => 'required|date',
            'dte_id' => 'nullable|integer',
            'received' => 'present|array|max:5000',
            'received.*.caravan_id' => 'nullable|integer',
            'received.*.identification' => 'nullable|string|max:30',
            'received.*.weight' => 'nullable|numeric|max:2000',
            'received.*.body_condition' => 'nullable|numeric',
            'missing' => 'nullable|array|max:5000',
            'missing.*' => 'integer',
            'reason' => 'nullable|string|max:1000',
            'receipt_sheet_id' => 'nullable|integer',
            'pages' => 'nullable|array|max:100',
            'pages.*' => 'integer|min:1',
            'unlisted' => 'nullable|array|max:500',
            'unlisted.*.identification' => 'required|string|max:30',
            'unlisted.*.sex' => 'nullable|string|in:M,H,m,h',
            'unlisted.*.breed' => 'nullable|string|max:60',
            'unlisted.*.coat' => 'nullable|string|max:60',
            'unlisted.*.weight' => 'nullable|numeric|gt:0|max:2000',
            'unlisted.*.body_condition' => 'nullable|numeric',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.in' => 'La recepción es manual (MANUAL), en manga (CHUTE) o desde una planilla ING-03 (SHEET).',
            'unlisted.*.identification.required' => 'Falta la caravana de un animal sin DTE.',
            'received_at.required' => 'Falta la fecha de recepción.',
        ];
    }
}
