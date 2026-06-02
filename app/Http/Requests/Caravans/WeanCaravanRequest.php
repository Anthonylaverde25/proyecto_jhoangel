<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class WeanCaravanRequest extends FormRequest
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
            'target_batch_id' => ['required', 'integer', 'exists:batches,id'],
            'weaning_date'    => ['required', 'date', 'date_format:Y-m-d'],
            'weaning_weight'  => ['required', 'numeric', 'min:0.1'],
            'new_category'    => ['nullable', 'string', 'in:novillito,vaquillona'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ];
    }
}
