<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Lifecycle of an entry order. Its own enum, not TransferOrderStatus: an entry order waits for a
 * document (the DTE) that no other order waits for, and it is fulfilled by loading that document,
 * not by executing anything at the chute.
 */
enum EntryOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case AWAITING_DTE = 'AWAITING_DTE';
    case PARTIAL = 'PARTIAL';
    case COMPLETED = 'COMPLETED';
    case CLOSED_INCOMPLETE = 'CLOSED_INCOMPLETE';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::AWAITING_DTE => 'En espera de DTE',
            self::PARTIAL => 'DTE parcial',
            self::COMPLETED => 'Completa',
            self::CLOSED_INCOMPLETE => 'Cerrada incompleta',
            self::CANCELLED => 'Anulada',
        };
    }

    /**
     * Still expecting caravans: a DTE can be loaded against it.
     */
    public function acceptsDte(): bool
    {
        return $this === self::AWAITING_DTE || $this === self::PARTIAL;
    }

    public function isOpen(): bool
    {
        return $this === self::DRAFT || $this->acceptsDte();
    }

    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }
}
