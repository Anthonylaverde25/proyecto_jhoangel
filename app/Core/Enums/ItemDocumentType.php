<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Stock operations (DS14). PURCHASE, RETURN_TO_PROVIDER, DELIVERY_RECEIPT and VALUATION come from a
 * provider document; the rest are loaded by the operator. Every line of every operation is a
 * stock-tracked article. VALUATION adds value without moving quantities (delivery C, DC15). A transfer
 * is a TRANSFER_OUT in the origin and a TRANSFER_IN in the destination (delivery D, DD7).
 */
enum ItemDocumentType: string
{
    case PURCHASE = 'PURCHASE';
    case INITIAL = 'INITIAL';
    case CONSUMPTION = 'CONSUMPTION';
    case COUNT_ADJUSTMENT = 'COUNT_ADJUSTMENT';
    case RETURN_TO_PROVIDER = 'RETURN_TO_PROVIDER';
    case REVERSAL = 'REVERSAL';
    case DELIVERY_RECEIPT = 'DELIVERY_RECEIPT';
    case VALUATION = 'VALUATION';
    case TRANSFER_OUT = 'TRANSFER_OUT';
    case TRANSFER_IN = 'TRANSFER_IN';
    case SEMEN_RECEIPT = 'SEMEN_RECEIPT';

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'Ingreso por compra',
            self::INITIAL => 'Stock inicial',
            self::CONSUMPTION => 'Consumo',
            self::COUNT_ADJUSTMENT => 'Conteo',
            self::RETURN_TO_PROVIDER => 'Devolución al proveedor',
            self::REVERSAL => 'Anulación',
            self::DELIVERY_RECEIPT => 'Ingreso por remito',
            self::VALUATION => 'Valorización',
            self::TRANSFER_OUT => 'Transferencia · salida',
            self::TRANSFER_IN => 'Transferencia · entrada',
            self::SEMEN_RECEIPT => 'Ingreso de semen',
        };
    }

    /** +1 inbound, -1 outbound, 0 value only; null when it depends on the line (count, reversal). */
    public function direction(): ?int
    {
        return match ($this) {
            self::PURCHASE, self::INITIAL, self::DELIVERY_RECEIPT, self::TRANSFER_IN, self::SEMEN_RECEIPT => 1,
            self::VALUATION => 0,
            self::CONSUMPTION, self::RETURN_TO_PROVIDER, self::TRANSFER_OUT => -1,
            self::COUNT_ADJUSTMENT, self::REVERSAL => null,
        };
    }

    /** Backed by a provider document: only voiding that document reverses it (DS20). */
    public function comesFromFiscalDocument(): bool
    {
        return in_array($this, [self::PURCHASE, self::RETURN_TO_PROVIDER, self::DELIVERY_RECEIPT, self::VALUATION], true);
    }

    public function isTransfer(): bool
    {
        return $this === self::TRANSFER_OUT || $this === self::TRANSFER_IN;
    }
}
