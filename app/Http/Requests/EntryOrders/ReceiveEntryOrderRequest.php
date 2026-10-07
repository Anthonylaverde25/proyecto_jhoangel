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
            'method' => 'required|string|in:MANUAL,SHEET,manual,sheet',
            'received_at' => 'required|date',
            'dte_id' => 'nullable|required_without:receipt_sheet_id|integer',
            ...self::animalRules(),
            'missing_head_count' => 'nullable|integer|min:0|max:5000',
            // Manual reception: the head that arrived, confirmed against the DTE. Closes it.
            'received_head_count' => 'nullable|integer|min:0|max:5000',
            // The SENASA TRI its caravans were read from, if one was attached.
            'tri_number' => 'nullable|string|max:40',
            'reason' => 'nullable|string|max:1000',
            'receipt_sheet_id' => 'nullable|required_if:method,SHEET,sheet|integer',
            'pages' => 'nullable|array|max:100',
            'pages.*' => 'integer|min:1',
        ];
    }

    /**
     * The animals that arrived, one per caravan written down. Breed and category come by position (a
     * code, or the manual reception's selector) or as written (`*_text`). Whether sex, category and
     * breed are needed is decided by EntryOrderReceptionService, which reports each missing cell by row.
     *
     * @return array<string, mixed>
     */
    public static function animalRules(string $prefix = ''): array
    {
        return [
            "{$prefix}animals" => 'present|array|max:5000',
            "{$prefix}animals.*.caravana" => 'present|nullable|string|max:30',
            "{$prefix}animals.*.sex" => 'nullable|string|max:1',
            "{$prefix}animals.*.breed_position" => 'nullable|integer|min:1|max:10',
            "{$prefix}animals.*.category_position" => 'nullable|integer|min:1|max:10',
            "{$prefix}animals.*.weight" => 'nullable|numeric|max:2000',
            "{$prefix}animals.*.body_condition" => 'nullable|numeric',
            // Written on an ING-03 printed with words: the server resolves them against the order.
            "{$prefix}animals.*.breed_text" => 'nullable|string|max:40',
            "{$prefix}animals.*.color_text" => 'nullable|string|max:40',
            "{$prefix}animals.*.category_text" => 'nullable|string|max:40',
            // What the chute saw on it as it came off the truck.
            "{$prefix}animals.*.arrival_findings" => 'nullable|array|max:3',
            "{$prefix}animals.*.arrival_findings.*" => 'string|in:EYE,EAR,LIMB',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.in' => 'La recepción es manual (MANUAL) o desde una planilla ING-03 (SHEET).',
            'dte_id.required_without' => 'Indicá el DTE que se recibe.',
            'received_at.required' => 'Falta la fecha de recepción.',
            'animals.*.arrival_findings.*.in' => 'Las lesiones al arribo son ojo (EYE), oreja (EAR) o aplomo (LIMB).',
        ];
    }
}
