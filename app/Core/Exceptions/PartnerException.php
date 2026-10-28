<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Broken rules of a partner (supplier, customer or both) and its contacts, tied to the field so
 * the form can mark it.
 */
final class PartnerException extends FieldValidationException
{
    public static function cuitLocked(): self
    {
        return self::on('cuit', 'El CUIT no se puede cambiar: el proveedor ya tiene comprobantes registrados.');
    }

    /** C1. */
    public static function withoutRole(): self
    {
        return self::on('roles', 'Marcá al menos un rol: qué nos vende o qué nos compra.');
    }

    /** C9. */
    public static function defaultContactInUse(): self
    {
        return self::on('contact', 'Es la persona de contacto predeterminada: elegí antes otra persona predeterminada.');
    }
}
