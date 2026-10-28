<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Broken rules of an exit order, tied to the field or the row that broke them ("lines.3.caravan_id")
 * so the grid marks each cell on the click that confirms.
 */
final class ExitOrderDomainException extends FieldValidationException
{
    public static function invalidTransition(string $fromLabel, string $action): self
    {
        return self::on('status', "Una orden {$fromLabel} no se puede {$action}.");
    }

    public static function emptyRoll(): self
    {
        return self::on('caravan_ids', 'La orden necesita al menos un animal.');
    }

    public static function repeatedAnimal(string $label): self
    {
        return self::on('caravan_ids', "{$label} está dos veces en la orden.");
    }

    public static function rollFixed(): self
    {
        return self::on('caravan_ids', 'Ya salieron animales de esta orden: la nómina no se cambia.');
    }

    public static function reasonRequired(string $field = 'reason'): self
    {
        return self::on($field, 'Indicá el motivo.');
    }

    public static function notInRoll(string $field, string $label): self
    {
        return self::on($field, "{$label} no está pendiente en esta orden.");
    }

    public static function buyerInvalid(string $message): self
    {
        return self::on('buyer_partner_id', $message);
    }

    public static function dteTaken(string $number): self
    {
        return self::on('dte_number', "El DTE {$number} ya está cargado en otro egreso.");
    }

    public static function dteOnCancelled(): self
    {
        return self::on('dte_number', 'La orden está anulada: no se le cargan DTE.');
    }
}
