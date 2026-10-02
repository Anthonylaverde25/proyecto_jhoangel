<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * A caravan that entered with a DTE of the order. It exists only once the caravan was created:
 * an entry order has no roll before its documents arrive.
 */
final class EntryOrderAnimalEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly string $identification,
        private readonly string $sex,
        private readonly ?int $breedPosition,
        private readonly ?int $caravanMovementId,
        private readonly ?float $entryWeight = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getIdentification(): string
    {
        return $this->identification;
    }

    public function getSex(): string
    {
        return $this->sex;
    }

    public function getBreedPosition(): ?int
    {
        return $this->breedPosition;
    }

    public function getCaravanMovementId(): ?int
    {
        return $this->caravanMovementId;
    }

    public function getEntryWeight(): ?float
    {
        return $this->entryWeight;
    }
}
