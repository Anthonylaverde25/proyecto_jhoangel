<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class GestationLossRequest extends FormRequest
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
            'loss_reason_id' => 'required|integer|exists:gestation_loss_reasons,id',
            'loss_notes'     => 'nullable|string',
            'loss_date'      => 'required|date_format:Y-m-d',
        ];
    }
}
