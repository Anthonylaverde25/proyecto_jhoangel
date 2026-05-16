<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Core\Enums\AnimalSex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

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
            'teeth'          => 'required|integer|min:0|max:99',
            'entry_weight'   => 'nullable|numeric',
            'breed'          => 'nullable|string',
            'breed_id'       => 'nullable|integer|exists:breeds,id',
            'sex'            => ['nullable', new Enum(AnimalSex::class)],
            'batch_id'       => 'nullable|integer|exists:batches,id',
            'farm_id'        => 'nullable|integer|exists:farms,id',
            'is_empty'       => 'nullable|boolean',
        ];
    }
}
