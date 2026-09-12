<?php

declare(strict_types=1);

namespace App\Http\Requests\Veterinary;

use Illuminate\Foundation\Http\FormRequest;

class IssueVeterinaryPortalTokenRequest extends FormRequest
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
        $maxTtl = (int) config('livestock.veterinary_portal.max_ttl_hours', 720);

        return [
            'veterinarian_id' => ['required', 'integer', 'exists:veterinarians,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],

            // ADR-16: the narrowest and preferred scope — this link opens one extraction act.
            'diagnostic_protocol_id' => ['nullable', 'integer', 'exists:diagnostic_protocols,id'],
            'label' => ['nullable', 'string', 'max:150'],
            'ttl_hours' => ['nullable', 'integer', 'min:1', 'max:' . $maxTtl],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000'],

            // Entrega por correo. El enlace en claro sólo existe en esta respuesta, así que el
            // envío ocurre acá o no ocurre nunca para este token.
            'send_email' => ['nullable', 'boolean'],
            'recipient_email' => ['nullable', 'email', 'max:150'],
            'sender_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
