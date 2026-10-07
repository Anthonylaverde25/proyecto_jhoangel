<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Lifecycle of an entry order. Its own enum, not TransferOrderStatus: an entry order waits for a
 * document (the DTE) that no other order waits for, and then for the animals that document lists.
 *
 * The status answers one question, what the order is waiting for: documents (AWAITING_DTE),
 * animals (IN_TRANSIT) or the caravans of head already received by count (RECEIVED). An order is
 * COMPLETED (or CLOSED_INCOMPLETE) only once every head received is an animal with its caravan.
 * How far it got is told by counters, not by statuses.
 */
enum EntryOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case AWAITING_DTE = 'AWAITING_DTE';
    case IN_TRANSIT = 'IN_TRANSIT';
    case RECEIVED = 'RECEIVED';
    case COMPLETED = 'COMPLETED';
    case CLOSED_INCOMPLETE = 'CLOSED_INCOMPLETE';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::AWAITING_DTE => 'En espera de DTE',
            self::IN_TRANSIT => 'En tránsito',
            self::RECEIVED => 'Recibida · por identificar',
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

    /**
     * Nothing more arrives, but some head received have no caravan yet: they are identified by hand
     * or on an ING-03, and the order closes with the last one.
     */
    public function awaitsIdentification(): bool
    {
        return $this === self::RECEIVED;
    }

    public function isOpen(): bool
    {
        return $this === self::DRAFT || $this->acceptsReception() || $this->awaitsIdentification();
    }

    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }
}
