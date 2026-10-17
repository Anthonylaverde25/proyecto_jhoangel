<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class RecordCaravanWeightRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'weight'           => 'required|numeric|gt:0|max:2000',
            'weighing_date'    => 'required|date',
            'notes'            => 'nullable|string',
            ...WeighingFieldRules::rules(),
        ];
    }
}
