<?php

declare(strict_types=1);

namespace App\Http\Requests\ExitOrders;

use Illuminate\Foundation\Http\FormRequest;

/** The death of a pending animal, with the fields of "Registrar muerte" (01 §5.1). */
class ExitLineDeathRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'death_date' => ['required', 'date_format:Y-m-d'],
            'death_cause_id' => ['required', 'integer'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'death_batch_id' => ['nullable', 'integer'],
        ];
    }
}
