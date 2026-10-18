<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Stock operations (DS14). PURCHASE and RETURN_TO_PROVIDER come from a provider document; the rest
 * are loaded by the operator. Every line of every operation is a stock-tracked article.
 */
enum ItemDocumentType: string
{
    case PURCHASE = 'PURCHASE';
    case INITIAL = 'INITIAL';
    case CONSUMPTION = 'CONSUMPTION';
    case COUNT_ADJUSTMENT = 'COUNT_ADJUSTMENT';
    case RETURN_TO_PROVIDER = 'RETURN_TO_PROVIDER';
    case REVERSAL = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'Ingreso por compra',
            self::INITIAL => 'Stock inicial',
            self::CONSUMPTION => 'Consumo',
            self::COUNT_ADJUSTMENT => 'Conteo',
            self::RETURN_TO_PROVIDER => 'Devolución al proveedor',
            self::REVERSAL => 'Anulación',
        };
    }

    /** +1 inbound, -1 outbound; null when it depends on the line (count, reversal). */
    public function direction(): ?int
    {
        return match ($this) {
            self::PURCHASE, self::INITIAL => 1,
            self::CONSUMPTION, self::RETURN_TO_PROVIDER => -1,
            self::COUNT_ADJUSTMENT, self::REVERSAL => null,
        };
    }

    /** Backed by a provider document: only voiding that document reverses it (DS20). */
    public function comesFromFiscalDocument(): bool
    {
        return $this === self::PURCHASE || $this === self::RETURN_TO_PROVIDER;
    }
}
