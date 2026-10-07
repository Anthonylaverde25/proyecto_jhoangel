<?php

declare(strict_types=1);

namespace App\Core\Entities;

use DateTimeInterface;

/**
 * One official transit document loaded for an entry order. It declares how many head move: the
 * caravans are only known when the animals arrive, and each one received is recorded against it.
 * A manual reception confirms head, not caravans: head received whose caravan is not written yet
 * are "uncaravaned", and writing a caravan later identifies one of them. Received is caravans plus
 * uncaravaned head; what is in transit is head declared minus received minus declared missing. Its
 * origin is the order's.
 */
final class EntryOrderDteEntity
{
    private bool $changed = false;

    /**
     * @param EntryOrderAnimalEntity[] $animals the caravans received on it
     * @param ?int $storedReceivedCount caravans received, when they were not loaded (as in the list)
     */
    public function __construct(
        private readonly ?int $id,
        private readonly string $dteNumber,
        private readonly string $dteDate,
        private int $headCount,
        private int $missingHeadCount = 0,
        private array $animals = [],
        private readonly ?int $loadedByUserId = null,
        private readonly ?string $observations = null,
        private readonly ?string $loadedByUserName = null,
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly ?int $storedReceivedCount = null,
        private int $uncaravanedHeadCount = 0
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

    /**
     * Head the document declares.
     */
    public function getHeadCount(): int
    {
        return $this->headCount;
    }

    /**
     * Head declared as never arriving.
     */
    public function getMissingHeadCount(): int
    {
        return $this->missingHeadCount;
    }

    /**
     * Head received: the caravans written down plus the head counted whose caravan is not known yet.
     */
    public function receivedCount(): int
    {
        return $this->caravanedCount() + $this->uncaravanedHeadCount;
    }

    /**
     * Caravans received on it.
     */
    public function caravanedCount(): int
    {
        return $this->storedReceivedCount !== null
            ? $this->storedReceivedCount + count($this->unsavedAnimals())
            : count($this->animals);
    }

    /**
     * Head received whose caravan has not been written yet.
     */
    public function getUncaravanedHeadCount(): int
    {
        return $this->uncaravanedHeadCount;
    }

    /**
     * Lines an ING-03 of it expects: head in transit plus head received without caravan.
     */
    public function toIdentifyCount(): int
    {
        return $this->pendingCount() + $this->uncaravanedHeadCount;
    }

    public function receivedCountBySex(string $sex): int
    {
        return count(array_filter($this->animals, fn (EntryOrderAnimalEntity $a) => $a->getSex() === $sex));
    }

    /**
     * Head still on their way: declared, not received and not declared missing.
     */
    public function pendingCount(): int
    {
        return max(0, $this->headCount - $this->receivedCount() - $this->missingHeadCount);
    }

    /**
     * Animals received over the head the document declares.
     */
    public function excessCount(): int
    {
        return max(0, $this->receivedCount() - $this->headCount);
    }

    /**
     * Head already settled one way or the other: the floor a correction of the head can go to.
     */
    public function accountedCount(): int
    {
        return $this->receivedCount() + $this->missingHeadCount;
    }

    public function addAnimal(EntryOrderAnimalEntity $animal): void
    {
        $this->animals[] = $animal;
    }

    /**
     * Head confirmed as arrived by count: received, their caravans still to write.
     */
    public function countHeads(int $head): void
    {
        $this->uncaravanedHeadCount += $head;
        $this->changed = true;
    }

    /**
     * Caravans written for head already counted: they stop being uncaravaned, not received again.
     */
    public function identify(int $head): void
    {
        $this->uncaravanedHeadCount -= min($head, $this->uncaravanedHeadCount);
        $this->changed = true;
    }

    public function declareMissing(int $head): void
    {
        $this->missingHeadCount += $head;
        $this->changed = true;
    }

    public function correctHeadCount(int $headCount): void
    {
        $this->headCount = $headCount;
        $this->changed = true;
    }

    /**
     * @return EntryOrderAnimalEntity[]
     */
    public function unsavedAnimals(): array
    {
        return array_values(array_filter($this->animals, fn (EntryOrderAnimalEntity $a) => $a->getId() === null));
    }

    /**
     * A stored DTE whose declared, missing or uncaravaned head changed in this operation.
     */
    public function isChanged(): bool
    {
        return $this->changed;
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
