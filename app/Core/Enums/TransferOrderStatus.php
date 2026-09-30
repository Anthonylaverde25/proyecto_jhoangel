<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Lifecycle of a transfer order.
 *
 * DRAFT is produced by "Guardar borrador": an order being prepared, editable, that commits no
 * animal and cannot be printed or executed until it is issued.
 */
enum TransferOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case PARTIAL = 'PARTIAL';
    case EXECUTED = 'EXECUTED';
    case CLOSED_INCOMPLETE = 'CLOSED_INCOMPLETE';
    case CANCELLED = 'CANCELLED';

    /**
     * Whether the order still commits animals and can be executed against. A draft does not:
     * it is a proposal, and two drafts may well name the same animals.
     */
    public function isOpen(): bool
    {
        return $this === self::ISSUED || $this === self::PARTIAL;
    }

    /**
     * Whether the order still holds its source batch, so no other order may be created for it.
     * Wider than isOpen(): a draft counts too, otherwise drafts pile up on the same batch.
     */
    public function isActive(): bool
    {
        return $this === self::DRAFT || $this->isOpen();
    }

    /**
     * Only a draft can have its destinations and roll rewritten.
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::ISSUED => 'Emitida',
            self::PARTIAL => 'Parcial',
            self::EXECUTED => 'Ejecutada',
            self::CLOSED_INCOMPLETE => 'Cerrada incompleta',
            self::CANCELLED => 'Anulada',
        };
    }
}
