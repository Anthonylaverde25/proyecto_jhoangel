<?php

declare(strict_types=1);

namespace App\Http\Requests\Veterinary;

use Illuminate\Foundation\Http\FormRequest;

class VoidDiagnosticProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A void without a reason is indistinguishable from data loss in an audit.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
