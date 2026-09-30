<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What happened to one pregnant female of a birth order. Declared by whoever walks the round —
 * marked on the PAR-01 sheet (V / M / A) or chosen on the screen — and never inferred from a calf
 * tag being written or left blank.
 */
enum BirthOutcome: string
{
    case LIVE = 'LIVE';
    case STILLBORN = 'STILLBORN';
    case ABORTION = 'ABORTION';

    public function label(): string
    {
        return match ($this) {
            self::LIVE => 'Parió',
            self::STILLBORN => 'Nacido muerto',
            self::ABORTION => 'Aborto',
        };
    }

    /**
     * The letter printed next to its box on the sheet.
     */
    public function sheetMark(): string
    {
        return match ($this) {
            self::LIVE => 'V',
            self::STILLBORN => 'M',
            self::ABORTION => 'A',
        };
    }

    /**
     * The gestation loss reason a lost pregnancy is closed with. Null for a live calving.
     */
    public function lossReasonCode(): ?string
    {
        return match ($this) {
            self::LIVE => null,
            self::STILLBORN => 'STILLBORN',
            self::ABORTION => 'ABORTION',
        };
    }

    /**
     * A full-term calving: the female calved, even if the calf was born dead.
     */
    public function isCalving(): bool
    {
        return $this !== self::ABORTION;
    }

    /**
     * What a sheet or a screen wrote, as an outcome. Accepts the code, the printed letter and the
     * usual words, with or without accents or case. Anything else is not an outcome, and the caller
     * decides what to say about it.
     */
    public static function fromText(?string $text): ?self
    {
        $value = strtoupper(trim(strtr((string) $text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U'])));
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return match ($value) {
            'LIVE', 'V', 'VIVO', 'VIVA', 'PARIO', 'PARTO', 'NACIO VIVO' => self::LIVE,
            'STILLBORN', 'M', 'MUERTO', 'MUERTA', 'NACIDO MUERTO', 'NACIO MUERTO' => self::STILLBORN,
            'ABORTION', 'A', 'ABORTO', 'ABORTADA' => self::ABORTION,
            default => null,
        };
    }
}
