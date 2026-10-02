<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A DTE and the caravans it lists. Sex and breed per caravan are optional here: whether the order
 * needs them is decided by EntryOrderDteService, which reports each missing cell by row.
 */
final class LoadEntryOrderDteRequest extends FormRequest
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
        return self::dteRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::dteMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public static function dteRules(string $prefix = ''): array
    {
        return [
            "{$prefix}dte_number" => 'required|string|max:40',
            "{$prefix}dte_date" => 'required|date|before_or_equal:today',
            "{$prefix}entered_at" => 'required|date|before_or_equal:today',
            "{$prefix}observations" => 'nullable|string|max:2000',
            "{$prefix}animals" => 'required|array|min:1|max:5000',
            "{$prefix}animals.*.caravana" => 'present|nullable|string|max:30',
            "{$prefix}animals.*.sex" => 'nullable|string|max:1',
            "{$prefix}animals.*.breed_position" => 'nullable|integer|min:1|max:10',
            "{$prefix}animals.*.weight" => 'nullable|numeric|max:2000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function dteMessages(string $prefix = ''): array
    {
        return [
            "{$prefix}dte_number.required" => 'Falta el número de DTE.',
            "{$prefix}dte_date.required" => 'Falta la fecha del DTE.',
            "{$prefix}dte_date.before_or_equal" => 'La fecha del DTE no puede ser futura.',
            "{$prefix}entered_at.required" => 'Falta la fecha de ingreso.',
            "{$prefix}entered_at.before_or_equal" => 'La fecha de ingreso no puede ser futura.',
            "{$prefix}animals.required" => 'El DTE no trae caravanas.',
            "{$prefix}animals.min" => 'El DTE no trae caravanas.',
        ];
    }
}
