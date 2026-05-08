<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class CaravanMovementEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly string $renspa,
        private readonly string $type, // ORIGIN, ENTRY, EXIT, TRANSFER
        private readonly \DateTimeInterface $movementDate,
        private readonly ?string $observations = null
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getRenspa(): string
    {
        return $this->renspa;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getMovementDate(): \DateTimeInterface
    {
        return $this->movementDate;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }
}
