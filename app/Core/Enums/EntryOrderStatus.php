<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Lifecycle of an entry order. Its own enum, not TransferOrderStatus: an entry order waits for a
 * document (the DTE) that no other order waits for, and then for the animals that document lists.
 *
 * The status answers one question, what the order is waiting for: documents (AWAITING_DTE) or
 * animals (IN_TRANSIT). How far it got is told by counters, not by statuses.
 */
enum EntryOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case AWAITING_DTE = 'AWAITING_DTE';
    case IN_TRANSIT = 'IN_TRANSIT';
    case COMPLETED = 'COMPLETED';
    case CLOSED_INCOMPLETE = 'CLOSED_INCOMPLETE';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::AWAITING_DTE => 'En espera de DTE',
            self::IN_TRANSIT => 'En tránsito',
            self::COMPLETED => 'Completa',
            self::CLOSED_INCOMPLETE => 'Cerrada incompleta',
            self::CANCELLED => 'Anulada',
        };
    }

    /**
     * Some head bought have no DTE yet: another document can be loaded against it.
     */
    public function acceptsDte(): bool
    {
        return $this === self::AWAITING_DTE;
    }

    /**
     * Caravans of its DTEs may still arrive. The order also needs some caravan left PENDING.
     */
    public function acceptsReception(): bool
    {
        return $this === self::AWAITING_DTE || $this === self::IN_TRANSIT;
    }

    public function isOpen(): bool
    {
        return $this === self::DRAFT || $this->acceptsReception();
    }

    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }
}
