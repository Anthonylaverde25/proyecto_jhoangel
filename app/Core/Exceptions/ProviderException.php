<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Broken rules of a provider's identity, tied to the field so the form can mark it.
 */
final class ProviderException extends FieldValidationException
{
    public static function cuitLocked(): self
    {
        return self::on('cuit', 'El CUIT no se puede cambiar: el proveedor ya tiene comprobantes registrados.');
    }
}
