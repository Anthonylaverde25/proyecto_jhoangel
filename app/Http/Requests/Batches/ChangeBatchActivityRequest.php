<?php

declare(strict_types=1);

namespace App\Http\Requests\Batches;

use Illuminate\Foundation\Http\FormRequest;

class ChangeBatchActivityRequest extends FormRequest
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
            'activity_id' => 'required|integer|exists:activities,id',
            'weight'      => 'nullable|numeric|min:0',
        ];
    }
}
