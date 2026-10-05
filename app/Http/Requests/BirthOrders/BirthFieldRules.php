<?php

declare(strict_types=1);

namespace App\Http\Requests\BirthOrders;

/**
 * The shape of what the screen declares per female, shared by registering and executing. Whether it
 * makes sense — a calf tag free, a date inside the gestation, a sire that is a male — is checked by
 * the PAR-01 processing, row by row, like on a sheet.
 */
final class BirthFieldRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'animals.*.caravan_id' => 'required|integer',
            'animals.*.outcome' => 'nullable|string|in:LIVE,STILLBORN,PERINATAL_DEATH',
            'animals.*.overdue' => 'nullable|boolean',
            'animals.*.calf_identification' => 'nullable|string|max:100',
            'animals.*.calf_sex' => 'nullable|string|in:M,H',
            'animals.*.calf_weight' => 'nullable|numeric|gt:0|max:200',
            'animals.*.calf_breed_id' => 'nullable|integer',
            'animals.*.calf_color_id' => 'nullable|integer',
            'animals.*.calf_teeth' => 'nullable|integer|min:0|max:8',
            'animals.*.father_id' => 'nullable|integer',
            'animals.*.birth_date' => 'nullable|date|before_or_equal:today',
            'animals.*.observations' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'animals.*.outcome.in' => 'El resultado tiene que ser Parió, Nació muerto o Murió al pie. El aborto se registra en Monitoreo Gestacional.',
            'animals.*.calf_weight.gt' => 'El peso al nacer tiene que ser mayor a cero.',
            'animals.*.calf_weight.max' => 'El peso al nacer no puede superar los 200 kg.',
            'animals.*.birth_date.before_or_equal' => 'La fecha del parto no puede ser futura.',
        ];
    }
}
