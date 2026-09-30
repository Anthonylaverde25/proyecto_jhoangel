<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class LookupCaravansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'identifications' => 'required|array|min:1|max:1000',
            'identifications.*' => 'required|string|max:64',
        ];
    }
}
