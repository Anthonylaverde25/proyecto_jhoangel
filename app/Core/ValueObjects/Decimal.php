<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic over bcmath (RNF-01): quantities and money never pass through float.
 * Operations keep 10 decimals; round() goes half-up to the scale a column stores.
 */
final readonly class Decimal
{
    private const SCALE = 10;

    private function __construct(
        private string $value
    ) {
    }

    public static function of(string|int $value): self
    {
        $value = is_int($value) ? (string) $value : trim($value);
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a decimal number: {$value}");
        }

        return new self(bcadd($value, '0', self::SCALE));
    }

    public static function zero(): self
    {
        return new self(bcadd('0', '0', self::SCALE));
    }

    public function add(self $other): self
    {
        return new self(bcadd($this->value, $other->value, self::SCALE));
    }

    public function sub(self $other): self
    {
        return new self(bcsub($this->value, $other->value, self::SCALE));
    }

    public function mul(self $other): self
    {
        return new self(bcmul($this->value, $other->value, self::SCALE));
    }

    public function div(self $other): self
    {
        if ($other->isZero()) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return new self(bcdiv($this->value, $other->value, self::SCALE));
    }

    public function round(int $scale): self
    {
        return new self(bcadd(bcround($this->value, $scale), '0', self::SCALE));
    }

    public function negate(): self
    {
        return new self(bcmul($this->value, '-1', self::SCALE));
    }

    public function abs(): self
    {
        return $this->isNegative() ? $this->negate() : $this;
    }

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', self::SCALE) < 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', self::SCALE) > 0;
    }

    /** Rounded to the scale of the column it goes to, e.g. "5000.0000". */
    public function toScale(int $scale): string
    {
        return bcadd(bcround($this->value, $scale), '0', $scale);
    }
}
