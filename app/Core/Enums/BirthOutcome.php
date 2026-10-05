<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How one pregnant female of a birth order calved. Declared by whoever walks the round — marked on
 * the PAR-01 sheet (V / NM / M) or chosen on the screen — and never inferred from a calf tag being
 * written or left blank.
 *
 * The three are full-term calvings, and they differ in who a dead calf is charged to. A calf born
 * dead (STILLBORN) points at the mother: her gestation is closed as a loss and stays in her record.
 * A calf born alive that died at foot (PERINATAL_DEATH) points at the calf: the mother calved a live
 * calf, so her gestation closes successful.
 *
 * ABORTION is no longer an outcome of the sheet — an abortion is registered in Monitoreo
 * Gestacional and closes the line from there. It stays only to read the lines that were registered
 * with it before.
 */
enum BirthOutcome: string
{
    case LIVE = 'LIVE';
    case STILLBORN = 'STILLBORN';
    case PERINATAL_DEATH = 'PERINATAL_DEATH';
    /** Legacy: lines registered before the abortion left the sheet. Never accepted as input. */
    case ABORTION = 'ABORTION';

    public function label(): string
    {
        return match ($this) {
            self::LIVE => 'Parió',
            self::STILLBORN => 'Nació muerto',
            self::PERINATAL_DEATH => 'Murió al pie',
            self::ABORTION => 'Aborto',
        };
    }

    /**
     * The letters printed next to its box on the sheet.
     */
    public function sheetMark(): string
    {
        return match ($this) {
            self::LIVE => 'V',
            self::STILLBORN => 'NM',
            self::PERINATAL_DEATH => 'M',
            self::ABORTION => 'A',
        };
    }

    /**
     * The gestation loss reason the pregnancy is closed with. Null when the gestation closes
     * successful: a live calf, even one that died at foot afterwards.
     */
    public function lossReasonCode(): ?string
    {
        return match ($this) {
            self::STILLBORN => 'STILLBORN',
            self::ABORTION => 'ABORTION',
            default => null,
        };
    }

    /**
     * Whether the dead calf is charged to the mother's reproductive record.
     */
    public function chargedToMother(): bool
    {
        return $this === self::STILLBORN;
    }

    /**
     * A full-term calving: the female calved, whatever happened to the calf.
     */
    public function isCalving(): bool
    {
        return $this !== self::ABORTION;
    }

    /**
     * What a sheet or a screen wrote, as an outcome. Accepts the code, the printed letters and the
     * usual words, with or without accents or case. Anything else — the abortion included — is not
     * an outcome, and the caller decides what to say about it.
     */
    public static function fromText(?string $text): ?self
    {
        return match (self::normalize($text)) {
            'LIVE', 'V', 'VIVO', 'VIVA', 'PARIO', 'PARTO', 'NACIO VIVO' => self::LIVE,
            'STILLBORN', 'NM', 'NACIO MUERTO', 'NACIDO MUERTO', 'NACIDA MUERTA', 'MUERTO AL NACER' => self::STILLBORN,
            'PERINATAL_DEATH', 'M', 'MURIO', 'MUERTO', 'MUERTA', 'MURIO AL PIE', 'MUERTO AL PIE' => self::PERINATAL_DEATH,
            default => null,
        };
    }

    /**
     * Upper case, without accents and with single spaces: how every mark is compared.
     */
    public static function normalize(?string $text): string
    {
        $value = strtoupper(trim(strtr((string) $text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U'])));

        return (string) preg_replace('/\s+/', ' ', $value);
    }
}
