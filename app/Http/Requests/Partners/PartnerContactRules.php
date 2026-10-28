<?php

declare(strict_types=1);

namespace App\Http\Requests\Partners;

/**
 * A contact person (C6): name and phone always, the rest optional. Shared by the contact endpoints
 * and by the first contact sent with a new partner.
 */
final class PartnerContactRules
{
    /** @return array<string, list<string>> */
    public static function for(string $prefix = '', bool $required = true): array
    {
        $presence = $required ? 'required' : "required_with:{$prefix}";
        $key = $prefix === '' ? '' : "{$prefix}.";

        return [
            "{$key}name" => [$presence, 'string', 'max:120'],
            "{$key}phone" => [$presence, 'string', 'max:50'],
            "{$key}position" => ['nullable', 'string', 'max:120'],
            "{$key}email" => ['nullable', 'email', 'max:255'],
            "{$key}notes" => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $prefix = ''): array
    {
        $key = $prefix === '' ? '' : "{$prefix}.";

        return [
            "{$key}name.required" => 'Falta el nombre de la persona de contacto.',
            "{$key}name.required_with" => 'Falta el nombre de la persona de contacto.',
            "{$key}phone.required" => 'Falta el teléfono: es obligatorio para una persona de contacto.',
            "{$key}phone.required_with" => 'Falta el teléfono: es obligatorio para una persona de contacto.',
            "{$key}email.email" => 'El email no es válido.',
        ];
    }
}
