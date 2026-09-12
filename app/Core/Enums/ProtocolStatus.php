<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * ADR-9: a CONFIRMED protocol is immutable. Correcting a transcription error means
 * voiding it (which reverts its samples and derived findings) and reissuing.
 */
enum ProtocolStatus: string
{
    case DRAFT = 'DRAFT';
    case CONFIRMED = 'CONFIRMED';
    case VOIDED = 'VOIDED';

    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    public function countsForAptitude(): bool
    {
        return $this === self::CONFIRMED;
    }
}
