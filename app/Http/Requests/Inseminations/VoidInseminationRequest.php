<?php

declare(strict_types=1);

namespace App\Http\Requests\Inseminations;

use Illuminate\Foundation\Http\FormRequest;

class VoidInseminationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:255',
            'idempotency_key' => 'required|string|max:80',
        ];
    }
}
