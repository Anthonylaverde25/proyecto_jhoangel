<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * ADR-11: the two faces of `diagnostic_protocols`.
 *
 * An EXTRACTION_ACT certifies what came out of which animal, and is signed at the chute by
 * the acting field veterinarian. A LAB_REPORT states what the tubes yielded, and is signed by
 * the laboratory. They are separate legal acts with separate responsible professionals, so
 * they must never share a signature.
 */
enum DiagnosticProtocolType: string
{
    case EXTRACTION_ACT = 'EXTRACTION_ACT';
    case LAB_REPORT = 'LAB_REPORT';

    public function isAct(): bool
    {
        return $this === self::EXTRACTION_ACT;
    }

    public function isLabReport(): bool
    {
        return $this === self::LAB_REPORT;
    }

    /**
     * Only the laboratory can state a result date; at extraction time it does not exist yet.
     */
    public function requiresResultDate(): bool
    {
        return $this === self::LAB_REPORT;
    }

    /**
     * The document series used to mint the internal number (ADR-12). A laboratory report keeps
     * the number printed on the physical report instead.
     */
    public function numberSeries(): ?string
    {
        return $this === self::EXTRACTION_ACT ? 'ACT' : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::EXTRACTION_ACT => 'Acta de extracción',
            self::LAB_REPORT => 'Informe de laboratorio',
        };
    }
}
