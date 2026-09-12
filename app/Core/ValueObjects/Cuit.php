<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use Stringable;

/**
 * ADR-29: the identifier that makes a catalogue unnecessary.
 *
 * A CUIT is unique per legal person and carries its own check digit, so two records that name
 * the same institution can be recognised as the same even when somebody typed "Lab Rosario" and
 * somebody else typed "LABORATORIO ROSARIO". That is the whole reason institutions can be
 * described instead of registered.
 *
 * ADR-43: this DESCRIBES, it does not gate. An earlier version threw on a bad check digit, which
 * meant one mistyped digit in an OPTIONAL institution field aborted a whole chute session. Nothing
 * in the system reads this number to decide anything — it exists so the history of one institution
 * can be grouped — so refusing to store what the professional typed bought nothing and cost the
 * most expensive operation in the app.
 *
 * The check digit is still computed, and `hasValidCheckDigit()` reports it so the interface can
 * warn. Warning and blocking are different things: the professional is the authority on the CUIT of
 * the institution they worked with, and the system's job is to flag a likely typo, not overrule it.
 */
final class Cuit implements Stringable
{
    /** Positional weights of the modulo 11 algorithm, applied to the first ten digits. */
    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    private function __construct(private readonly string $digits)
    {
    }

    /**
     * Keeps the digits as typed. Never throws: see the class note on why this describes.
     */
    public static function fromString(string $raw): self
    {
        return new self(preg_replace('/\D/', '', $raw) ?? '');
    }

    /**
     * Null in, null out — and also null for a value with no digits at all, because "abc" and an
     * empty box say the same thing: nobody gave a CUIT.
     */
    public static function fromNullable(?string $raw): ?self
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $cuit = self::fromString($raw);

        return $cuit->digits === '' ? null : $cuit;
    }

    /** Eleven digits whose check digit closes. What the interface warns about when false. */
    public function isWellFormed(): bool
    {
        return strlen($this->digits) === 11 && self::checkDigitMatches($this->digits);
    }

    public function hasValidCheckDigit(): bool
    {
        return strlen($this->digits) === 11 && self::checkDigitMatches($this->digits);
    }

    /**
     * The digit these first ten would need, so a warning can say what was probably meant instead
     * of only that something is off. Null when there are not ten digits to work from.
     */
    public function expectedCheckDigit(): ?int
    {
        if (strlen($this->digits) !== 11) {
            return null;
        }

        $sum = 0;

        foreach (self::WEIGHTS as $position => $weight) {
            $sum += ((int) $this->digits[$position]) * $weight;
        }

        $remainder = $sum % 11;

        return match ($remainder) {
            0 => 0,
            1 => 9,
            default => 11 - $remainder,
        };
    }

    private static function checkDigitMatches(string $digits): bool
    {
        $sum = 0;

        foreach (self::WEIGHTS as $position => $weight) {
            $sum += ((int) $digits[$position]) * $weight;
        }

        $remainder = 11 - ($sum % 11);
        $expected = match ($remainder) {
            11 => 0,
            10 => -1, // Never valid: those numbers are issued under a different type prefix.
            default => $remainder,
        };

        return $expected === (int) $digits[10];
    }

    /** Comparison is always on the digits, never on the formatting. */
    public function equals(?self $other): bool
    {
        return $other !== null && $this->digits === $other->digits;
    }

    /** Stored and compared unformatted; formatted only for display. */
    public function value(): string
    {
        return $this->digits;
    }

    /**
     * Grouped by digits, so a malformed number still groups consistently with itself — which is
     * the whole job. It just may not correspond to a real taxpayer, and the UI says so.
     */
    public function formatted(): string
    {
        if (strlen($this->digits) !== 11) {
            return $this->digits;
        }

        return sprintf(
            '%s-%s-%s',
            substr($this->digits, 0, 2),
            substr($this->digits, 2, 8),
            substr($this->digits, 10, 1)
        );
    }

    public function __toString(): string
    {
        return $this->formatted();
    }
}
