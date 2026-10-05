<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ReceptionMethod;
use App\Core\Enums\ReceptionStatus;

/**
 * A caravan listed in a DTE of the order. It exists from the moment the DTE is loaded, in transit
 * (PENDING) until it is received or declared missing. How, when and by whom it was received is a
 * declared fact, kept as such.
 */
final class EntryOrderAnimalEntity
{
    private bool $receptionChanged = false;

    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly string $identification,
        private readonly string $sex,
        private readonly ?int $breedPosition,
        private ?int $caravanMovementId,
        private ?float $entryWeight = null,
        private ReceptionStatus $receptionStatus = ReceptionStatus::PENDING,
        private ?string $receivedAt = null,
        private ?ReceptionMethod $receptionMethod = null,
        private ?int $receivedByUserId = null
    ) {
    }

    public function markReceived(string $receivedAt, ReceptionMethod $method, ?int $userId, ?int $movementId, ?float $weight): void
    {
        $this->receptionStatus = ReceptionStatus::RECEIVED;
        $this->receivedAt = $receivedAt;
        $this->receptionMethod = $method;
        $this->receivedByUserId = $userId;
        $this->caravanMovementId = $movementId;
        $this->entryWeight = $weight;
        $this->receptionChanged = true;
    }

    public function markMissing(?int $userId): void
    {
        $this->receptionStatus = ReceptionStatus::MISSING;
        $this->receivedByUserId = $userId;
        $this->receptionChanged = true;
    }

    public function isPending(): bool
    {
        return $this->receptionStatus === ReceptionStatus::PENDING;
    }

    public function isReceptionChanged(): bool
    {
        return $this->receptionChanged;
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

    /**
     * The weight taken on reception, if any. A DTE brings no weights.
     */
    public function getEntryWeight(): ?float
    {
        return $this->entryWeight;
    }

    public function getReceptionStatus(): ReceptionStatus
    {
        return $this->receptionStatus;
    }

    public function getReceivedAt(): ?string
    {
        return $this->receivedAt;
    }

    public function getReceptionMethod(): ?ReceptionMethod
    {
        return $this->receptionMethod;
    }

    public function getReceivedByUserId(): ?int
    {
        return $this->receivedByUserId;
    }
}
