<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\GestationStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rule;

class UpsertCaravanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Auth logic is usually handled by middleware (Sanctum)
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'identification' => 'required|string',
            'category'       => 'nullable|string',
            'category_id'    => 'nullable|integer|exists:animal_categories,id',
            'subcategory_id' => 'nullable|integer|exists:animal_subcategories,id',
            'teeth'          => 'required|integer|min:0|max:99',
            'entry_weight'   => 'nullable|numeric',
            'breed'          => 'nullable|string',
            'breed_id'       => 'nullable|integer|exists:breeds,id',
            'color_id'       => 'nullable|integer|exists:colors,id',
            'sex'            => ['nullable', new Enum(AnimalSex::class)],
            'batch_id'       => 'nullable|integer|exists:batches,id',
            'farm_id'        => 'nullable|integer|exists:farms,id',
            'is_empty'       => 'nullable|boolean',
            'gestation_stage' => [
                Rule::requiredIf(fn() => $this->input('is_empty') === false && !$this->filled('gestation_months')),
                'nullable',
                new Enum(GestationStage::class)
            ],
            'gestation_months' => [
                Rule::requiredIf(fn() => $this->input('is_empty') === false && !$this->filled('gestation_stage')),
                'nullable',
                'numeric',
                'min:0',
                'max:12'
            ],
        ];
    }
}
