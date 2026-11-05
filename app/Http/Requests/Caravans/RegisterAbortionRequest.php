<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class RegisterAbortionRequest extends FormRequest
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
            'aborted_on'  => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes'       => ['nullable', 'string', 'max:500'],
            'becomes_cow' => ['nullable', 'boolean'],
        ];
    }
}

