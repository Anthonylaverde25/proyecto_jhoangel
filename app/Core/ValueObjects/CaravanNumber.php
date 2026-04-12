<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Exceptions\DomainException;

final readonly class CaravanNumber
{
    /**
     * @throws DomainException
     */
    public function __construct(private string $value)
    {
        if (trim($this->value) === '') {
            throw new DomainException("El número de caravana no puede estar vacío.");
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(CaravanNumber $other): bool
    {
        return $this->value === $other->value;
    }
}
