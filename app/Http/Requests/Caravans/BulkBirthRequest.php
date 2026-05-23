<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Core\Enums\AnimalSex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class BulkBirthRequest extends FormRequest
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
            'births' => 'required|array|min:1',
            'births.*.calf_identification' => 'required|string',
            'births.*.calf_sex'            => ['required', new Enum(AnimalSex::class)],
            'births.*.calf_category'       => 'nullable|string',
            'births.*.calf_teeth'          => 'nullable|integer|min:0|max:99',
            'births.*.calf_weight'         => 'nullable|numeric',
            'births.*.calf_breed_id'       => 'nullable|integer|exists:breeds,id',
            'births.*.birth_date'          => 'required|date_format:Y-m-d',
            'births.*.batch_id'            => 'required|integer|exists:batches,id',
            'births.*.mother_id'           => 'required|integer|exists:caravans,id',
            'births.*.father_id'           => 'nullable|integer|exists:caravans,id',
            'births.*.gestation_id'        => 'nullable|integer|exists:caravan_gestations,id',
        ];
    }
}
