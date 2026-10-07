<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * What a breed, coat or category written on a reception line points to, against the order:
 *
 * - MATCHED: one line of the order (its position).
 * - OUTSIDE_ORDER: a breed of the catalog the order does not declare (its breed and coat ids) —
 *   the animal is received with it and the difference is reported.
 * - AMBIGUOUS: several lines fit; `candidates` says which, for a person to pick.
 * - UNKNOWN: nothing fits, or something is missing to decide (`code` says what).
 *
 * `field` is the cell the answer is about: breed_text, color_text or category_text.
 */
final readonly class TroopLineMatch
{
    public const MATCHED = 'MATCHED';
    public const OUTSIDE_ORDER = 'OUTSIDE_ORDER';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const UNKNOWN = 'UNKNOWN';

    /**
     * @param list<string> $candidates
     */
    private function __construct(
        public string $status,
        public string $field,
        public ?int $position = null,
        public ?int $breedId = null,
        public ?int $colorId = null,
        public ?string $label = null,
        public ?string $code = null,
        public ?string $message = null,
        public array $candidates = []
    ) {
    }

    public static function matched(string $field, int $position): self
    {
        return new self(self::MATCHED, $field, position: $position);
    }

    public static function outsideOrder(int $breedId, ?int $colorId, string $label): self
    {
        return new self(self::OUTSIDE_ORDER, 'breed_text', breedId: $breedId, colorId: $colorId, label: $label);
    }

    /**
     * @param list<string> $candidates
     */
    public static function ambiguous(string $field, string $code, string $message, array $candidates): self
    {
        return new self(self::AMBIGUOUS, $field, code: $code, message: $message, candidates: $candidates);
    }

    /**
     * @param list<string> $candidates what the line could say instead
     */
    public static function unknown(string $field, string $code, string $message, array $candidates = []): self
    {
        return new self(self::UNKNOWN, $field, code: $code, message: $message, candidates: $candidates);
    }

    public function isMatched(): bool
    {
        return $this->status === self::MATCHED;
    }

    public function isOutsideOrder(): bool
    {
        return $this->status === self::OUTSIDE_ORDER;
    }

    /** Ambiguous or unknown: an error on the line's cell. */
    public function isError(): bool
    {
        return $this->status === self::AMBIGUOUS || $this->status === self::UNKNOWN;
    }
}
