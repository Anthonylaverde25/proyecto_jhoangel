<?php

declare(strict_types=1);

namespace App\Core\Entities;

use DateTimeInterface;

/**
 * One official transit document received for an entry order, with the caravans it brought.
 */
final class EntryOrderDteEntity
{
    /**
     * @param EntryOrderAnimalEntity[] $animals
     */
    public function __construct(
        private readonly ?int $id,
        private readonly string $dteNumber,
        private readonly string $dteDate,
        private readonly string $enteredAt,
        private readonly array $animals,
        private readonly ?int $loadedByUserId = null,
        private readonly ?string $observations = null,
        private readonly ?string $loadedByUserName = null,
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly ?int $storedHeadCount = null
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

    public function getEnteredAt(): string
    {
        return $this->enteredAt;
    }

    /**
     * @return EntryOrderAnimalEntity[]
     */
    public function getAnimals(): array
    {
        return $this->animals;
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
