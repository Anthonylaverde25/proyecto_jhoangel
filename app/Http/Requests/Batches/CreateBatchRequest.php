<?php

declare(strict_types=1);

namespace App\Http\Requests\Batches;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBatchRequest extends FormRequest
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
        $companyId = (int) $this->header('X-Company-ID');

        return [
            'name'          => 'required|string|max:255',
            'farm_id'       => 'nullable|integer|exists:farms,id',
            'activity_id'   => 'nullable|integer|exists:activities,id',
            'weight'        => 'nullable|numeric|min:0',
            'min_weight'    => 'nullable|numeric|min:0',
            'max_weight'    => 'nullable|numeric|min:0|gte:min_weight',
            'knows_to_eat'  => 'nullable|boolean',
            'age_in_months' => 'nullable|integer|min:0',
            'observaciones' => 'nullable|string',
            'batch_type_id' => [
                'required',
                'integer',
                Rule::exists('batch_types', 'id')->where('company_id', $companyId),
            ],
        ];
    }
}
