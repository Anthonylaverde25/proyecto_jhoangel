<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Exceptions\DomainException;

final readonly class CaravanNumber
{
    /**
     * @throws DomainException
     */
    public function __construct(private int $value)
    {
        if ($this->value <= 0) {
            throw new DomainException("El número de caravana debe ser un entero positivo.");
        }
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function equals(CaravanNumber $other): bool
    {
        return $this->value === $other->value;
    }
}
