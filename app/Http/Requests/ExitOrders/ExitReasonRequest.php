<?php

declare(strict_types=1);

namespace App\Http\Requests\ExitOrders;

use Illuminate\Foundation\Http\FormRequest;

/** Closing incomplete or cancelling asks why. */
class ExitReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Indicá el motivo.'];
    }
}
