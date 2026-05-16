<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class BulkRecordWeightRequest extends FormRequest
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
            'weights' => 'required|array|min:1',
            'weights.*.caravan_id'    => 'required|integer|exists:caravans,id',
            'weights.*.weight'        => 'required|numeric|min:0',
            'weights.*.weighing_date' => 'required|date',
            'weights.*.notes'         => 'nullable|string',
        ];
    }
}
