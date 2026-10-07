<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A DTE: its number, date and the head it declares. No caravans, arrival date nor weights: the
 * animals are received later.
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
            "{$prefix}observations" => 'nullable|string|max:2000',
            "{$prefix}head_count" => 'required|integer|min:1|max:5000',
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
            "{$prefix}head_count.required" => 'Faltan las cabezas que declara el DTE.',
            "{$prefix}head_count.min" => 'El DTE tiene que declarar al menos una cabeza.',
        ];
    }
}
