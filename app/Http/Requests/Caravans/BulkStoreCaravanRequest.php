<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Core\Enums\AnimalSex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

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
        return [
            'caravans' => 'required|array|min:1',
            'caravans.*.identification' => 'required|string',
            'caravans.*.category'       => 'nullable|string',
            'caravans.*.teeth'          => 'required|integer|min:0|max:99',
            'caravans.*.entry_weight'   => 'nullable|numeric',
            'caravans.*.breed'          => 'nullable|string',
            'caravans.*.breed_id'       => 'nullable|integer|exists:breeds,id',
            'caravans.*.sex'            => ['nullable', new Enum(AnimalSex::class)],
            'caravans.*.batch_id'       => 'nullable|integer|exists:batches,id',
            'caravans.*.farm_id'        => 'nullable|integer|exists:farms,id',
        ];
    }
}
