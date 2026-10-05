<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\BirthOutcome;

/**
 * The "resultado" cell of a PAR-01 row, read as what the round declared: at most one calving
 * outcome (V, NM or M) and, independently, whether the female was found past her due date without
 * calving (N).
 *
 * N together with an outcome is not a contradiction: she did not calve in time and calved later.
 * Two outcomes together are, and so is anything that is not one of the four marks. An A (abortion)
 * is recognised only to say it does not belong on this sheet.
 */
final class BirthSheetMark
{
    private const OVERDUE_MARKS = ['N', 'NO PARIO', 'NO', 'OVERDUE'];
    private const ABORTION_MARKS = ['A', 'ABORTO', 'ABORTADA', 'ABORTION'];

    /**
     * @param list<BirthOutcome> $outcomes every calving outcome marked
     * @param list<string> $unknown what was written and is not a mark
     */
    private function __construct(
        private readonly array $outcomes,
        private readonly bool $overdue,
        private readonly bool $abortion,
        private readonly array $unknown
    ) {
    }

    public static function parse(?string $raw): self
    {
        $outcomes = [];
        $overdue = false;
        $abortion = false;
        $unknown = [];

        foreach (self::tokens($raw) as $token) {
            $outcome = BirthOutcome::fromText($token);

            if ($outcome !== null) {
                if (!in_array($outcome, $outcomes, true)) {
                    $outcomes[] = $outcome;
                }
            } elseif (in_array($token, self::OVERDUE_MARKS, true)) {
                $overdue = true;
            } elseif (in_array($token, self::ABORTION_MARKS, true)) {
                $abortion = true;
            } else {
                $unknown[] = $token;
            }
        }

        return new self($outcomes, $overdue, $abortion, $unknown);
    }

    /**
     * Built from what a screen declares: an outcome code, the "no parió en fecha" box, or both.
     */
    public static function of(?BirthOutcome $outcome, bool $overdue): self
    {
        return new self($outcome !== null ? [$outcome] : [], $overdue, false, []);
    }

    /**
     * The calving outcome, when exactly one was marked.
     */
    public function outcome(): ?BirthOutcome
    {
        return count($this->outcomes) === 1 ? $this->outcomes[0] : null;
    }

    public function isOverdue(): bool
    {
        return $this->overdue;
    }

    /**
     * Only the N: an alert, no calving.
     */
    public function isOverdueOnly(): bool
    {
        return $this->overdue && $this->outcomes === [] && !$this->abortion && $this->unknown === [];
    }

    public function isAbortion(): bool
    {
        return $this->abortion;
    }

    public function isEmpty(): bool
    {
        return $this->outcomes === [] && !$this->overdue && !$this->abortion && $this->unknown === [];
    }

    /**
     * Two outcomes at once, or something that is not a mark.
     */
    public function isAmbiguous(): bool
    {
        return count($this->outcomes) > 1 || $this->unknown !== [];
    }

    /**
     * The letters, as the sheet prints them ("N, V"). For messages and the screen.
     */
    public function describe(): string
    {
        $marks = $this->overdue ? ['N'] : [];

        foreach ($this->outcomes as $outcome) {
            $marks[] = $outcome->sheetMark();
        }

        if ($this->abortion) {
            $marks[] = 'A';
        }

        return implode(', ', [...$marks, ...$this->unknown]);
    }

    /**
     * The marks of a cell. They are separated by commas (the scan's convention) or by any other
     * separator; a cell written as words ("nació muerto") is one mark.
     *
     * @return list<string>
     */
    private static function tokens(?string $raw): array
    {
        $text = BirthOutcome::normalize($raw);

        if ($text === '') {
            return [];
        }

        $parts = array_values(array_filter(
            array_map('trim', (array) preg_split('/[,;\/+|]+/', $text)),
            fn (string $part) => $part !== ''
        ));

        $tokens = [];
        foreach ($parts as $part) {
            if (self::isKnown($part) || !str_contains($part, ' ')) {
                $tokens[] = $part;
                continue;
            }

            // "N V" written with a space: each word is a mark of its own.
            foreach (explode(' ', $part) as $word) {
                $tokens[] = $word;
            }
        }

        return $tokens;
    }

    private static function isKnown(string $token): bool
    {
        return BirthOutcome::fromText($token) !== null
            || in_array($token, self::OVERDUE_MARKS, true)
            || in_array($token, self::ABORTION_MARKS, true);
    }
}
