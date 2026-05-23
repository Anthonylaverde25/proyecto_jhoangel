<?php

declare(strict_types=1);

namespace App\Core\Entities;

use InvalidArgumentException;

final class LineageEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly int $motherId,
        private readonly ?string $motherIdentification,
        private readonly ?int $fatherId,
        private readonly ?string $fatherIdentification,
        private readonly ?int $gestationId,
        private readonly string $birthDate,
        private bool $isNursing = true
    ) {
        if ($this->caravanId === $this->motherId) {
            throw new InvalidArgumentException("An animal cannot be its own mother.");
        }
        if ($this->fatherId !== null && $this->caravanId === $this->fatherId) {
            throw new InvalidArgumentException("An animal cannot be its own father.");
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getMotherId(): int
    {
        return $this->motherId;
    }

    public function getMotherIdentification(): ?string
    {
        return $this->motherIdentification;
    }

    public function getFatherId(): ?int
    {
        return $this->fatherId;
    }

    public function getFatherIdentification(): ?string
    {
        return $this->fatherIdentification;
    }

    public function getGestationId(): ?int
    {
        return $this->gestationId;
    }

    public function getBirthDate(): string
    {
        return $this->birthDate;
    }

    public function isNursing(): bool
    {
        return $this->isNursing;
    }

    public function wean(): void
    {
        $this->isNursing = false;
    }
}
