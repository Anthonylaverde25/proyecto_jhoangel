<?php

declare(strict_types=1);

namespace App\Http\Requests\ExitOrders;

use Illuminate\Foundation\Http\FormRequest;

/** "Registrar egreso" and "Vender": an exit that already happened, with buyer and rows. */
class RegisterExitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'buyer_partner_id' => ['required', 'integer'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ] + ExitLinesRules::rules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['buyer_partner_id.required' => 'Elegí el comprador.'] + ExitLinesRules::messages();
    }
}
