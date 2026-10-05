<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ReceptionStatus;
use DateTimeInterface;

/**
 * One official transit document loaded for an entry order, with the caravans it lists. The
 * document has no arrival date of its own: its caravans arrive one by one, maybe on several days.
 * Its origin is the order's.
 */
final class EntryOrderDteEntity
{
    /**
     * @param EntryOrderAnimalEntity[] $animals
     * @param array<string, int> $storedReceptionCounts caravans by reception status, when the caravans were not loaded
     */
    public function __construct(
        private readonly ?int $id,
        private readonly string $dteNumber,
        private readonly string $dteDate,
        private readonly array $animals,
        private readonly ?int $loadedByUserId = null,
        private readonly ?string $observations = null,
        private readonly ?string $loadedByUserName = null,
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly ?int $storedHeadCount = null,
        private readonly array $storedReceptionCounts = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDteNumber(): string
    {
        return $this->dteNumber;
    }

    public function getDteDate(): string
    {
        return $this->dteDate;
    }

    /**
     * @return EntryOrderAnimalEntity[]
     */
    public function getAnimals(): array
    {
        return $this->animals;
    }

    public function findAnimal(int $caravanId): ?EntryOrderAnimalEntity
    {
        foreach ($this->animals as $animal) {
            if ($animal->getCaravanId() === $caravanId) {
                return $animal;
            }
        }

        return null;
    }

    /**
     * The head the document brought. Read from the stored column when the caravans were not
     * loaded (as in the list), so a summary does not have to load every animal.
     */
    public function getHeadCount(): int
    {
        return $this->animals !== [] ? count($this->animals) : ($this->storedHeadCount ?? 0);
    }

    public function countBySex(string $sex): int
    {
        return count(array_filter($this->animals, fn (EntryOrderAnimalEntity $a) => $a->getSex() === $sex));
    }

    /**
     * Caravans in a reception status. Read from the stored counts when the caravans were not loaded.
     */
    public function countByReception(ReceptionStatus $status): int
    {
        if ($this->animals === []) {
            return $this->storedReceptionCounts[$status->value] ?? 0;
        }

        return count(array_filter($this->animals, fn (EntryOrderAnimalEntity $a) => $a->getReceptionStatus() === $status));
    }

    public function getLoadedByUserId(): ?int
    {
        return $this->loadedByUserId;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function getLoadedByUserName(): ?string
    {
        return $this->loadedByUserName;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }
}
