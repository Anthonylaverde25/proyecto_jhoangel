<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\GestationStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rule;

class BulkStoreCaravanRequest extends FormRequest
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
        $rules = [
            'caravans' => 'required|array|min:1',
            'caravans.*.identification' => 'required|string',
            'caravans.*.category'       => 'nullable|string',
            'caravans.*.category_id'    => 'nullable|integer|exists:animal_categories,id',
            'caravans.*.subcategory_id' => 'nullable|integer|exists:animal_subcategories,id',
            'caravans.*.teeth'          => 'required|integer|min:0|max:99',
            'caravans.*.entry_weight'   => 'nullable|numeric',
            'caravans.*.breed'          => 'nullable|string',
            'caravans.*.breed_id'       => 'nullable|integer|exists:breeds,id',
            'caravans.*.sex'            => ['nullable', new Enum(AnimalSex::class)],
            'caravans.*.batch_id'       => 'nullable|integer|exists:batches,id',
            'caravans.*.farm_id'        => 'nullable|integer|exists:farms,id',
            'caravans.*.is_empty'       => 'nullable|boolean',
        ];

        $caravans = $this->input('caravans', []);
        if (is_array($caravans)) {
            foreach ($caravans as $index => $caravan) {
                $rules["caravans.{$index}.gestation_stage"] = [
                    Rule::requiredIf(function () use ($caravan) {
                        $isEmpty = isset($caravan['is_empty']) ? filter_var($caravan['is_empty'], FILTER_VALIDATE_BOOLEAN) : null;
                        $hasMonths = isset($caravan['gestation_months']) && $caravan['gestation_months'] !== '';
                        return $isEmpty === false && !$hasMonths;
                    }),
                    'nullable',
                    new Enum(GestationStage::class)
                ];

                $rules["caravans.{$index}.gestation_months"] = [
                    Rule::requiredIf(function () use ($caravan) {
                        $isEmpty = isset($caravan['is_empty']) ? filter_var($caravan['is_empty'], FILTER_VALIDATE_BOOLEAN) : null;
                        $hasStage = isset($caravan['gestation_stage']) && $caravan['gestation_stage'] !== '';
                        return $isEmpty === false && !$hasStage;
                    }),
                    'nullable',
                    'numeric',
                    'min:0',
                    'max:12'
                ];
            }
        }

        return $rules;
    }
}
