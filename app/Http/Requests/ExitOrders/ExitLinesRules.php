<?php

declare(strict_types=1);

namespace App\Http\Requests\ExitOrders;

use App\Core\Enums\CalfAtFootDecision;
use Illuminate\Validation\Rule;

/** The rows of a load and its optional DTE, shared by "Registrar egreso" and the execution. */
final class ExitLinesRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'exit_date' => ['required', 'date_format:Y-m-d'],
            'exit_batch_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.caravan_id' => ['required', 'integer', 'distinct'],
            'lines.*.weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:3000'],
            'lines.*.price_per_kg' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.calf_decision' => ['nullable', Rule::enum(CalfAtFootDecision::class)],
            'dte' => ['nullable', 'array'],
            'dte.dte_number' => ['required_with:dte.head_count', 'nullable', 'string', 'max:40'],
            'dte.dte_date' => ['required_with:dte.dte_number', 'nullable', 'date_format:Y-m-d'],
            'dte.head_count' => ['required_with:dte.dte_number', 'nullable', 'integer', 'min:1'],
            'dte.observations' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'lines.required' => 'Cargá al menos un animal.',
            'lines.min' => 'Cargá al menos un animal.',
            'lines.*.caravan_id.distinct' => 'Ese animal está dos veces en la carga.',
            'lines.*.weight_kg.gt' => 'El peso tiene que ser mayor a 0 kg.',
            'lines.*.price_per_kg.gt' => 'El precio por kg tiene que ser mayor a 0.',
            'dte.dte_date.required_with' => 'Falta la fecha del DTE.',
            'dte.head_count.required_with' => 'Faltan las cabezas del DTE.',
        ];
    }
}
