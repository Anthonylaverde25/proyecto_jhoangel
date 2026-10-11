<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\EntryOrderDomainException;
use DateTimeInterface;

/**
 * One official transit document loaded for an entry order. It declares how many head move: the
 * caravans are only known when the animals arrive, and each one received is recorded against it.
 * A manual reception confirms head, not caravans: head received whose caravan is not written yet
 * are "uncaravaned", and writing a caravan later identifies one of them. Received is caravans plus
 * uncaravaned head (plus the ones written off before getting a caravan); what is in transit is head
 * declared minus received minus declared missing. Its origin is the order's.
 *
 * The uncaravaned head are kept by the batch they went to ("pending identification"), so each batch
 * counts the head it already holds; the pending of every batch add up to the uncaravaned head. A
 * caravan written later takes a pending head of its own batch, and the day the head arrived.
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
        private int $uncaravanedHeadCount = 0,
        /** @var array<int, int> batch id → head pending identification there */
        private array $pendingByBatch = [],
        private ?string $headsReceivedAt = null,
        private int $writtenOffHeadCount = 0,
        /** @var array<int, string> batch id → name, for display */
        private readonly array $batchNames = []
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
        return $this->caravanedCount() + $this->uncaravanedHeadCount + $this->writtenOffHeadCount;
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
     * Head confirmed as arrived by count on a day: received, their caravans still to write. They are
     * pending in the batches they went to, as declared.
     *
     * @param array<int, int> $byBatch batch id → head that went there; adds up to $head
     */
    public function countHeads(int $head, array $byBatch, string $receivedAt): void
    {
        $this->uncaravanedHeadCount += $head;
        $this->headsReceivedAt = $receivedAt;

        foreach ($byBatch as $batchId => $count) {
            if ($count > 0) {
                $this->pendingByBatch[$batchId] = ($this->pendingByBatch[$batchId] ?? 0) + $count;
            }
        }

        $this->changed = true;
    }

    /**
     * Which of these caravans identify a head already received, and the batch whose pending head
     * each one takes: its own batch while it has some, else another batch of the DTE that still has
     * (the split declared at the chute was off), else none — it is a new head. Changes nothing.
     *
     * @param list<int> $caravanBatchIds the batch each caravan goes to, in order
     * @return list<array{identifies: bool, from: ?int}>
     */
    public function identificationOf(array $caravanBatchIds): array
    {
        $pending = $this->pendingByBatch;
        $left = $this->uncaravanedHeadCount;
        $result = [];

        foreach ($caravanBatchIds as $batchId) {
            if ($left <= 0) {
                $result[] = ['identifies' => false, 'from' => null];
                continue;
            }

            $from = ($pending[$batchId] ?? 0) > 0 ? $batchId : null;

            foreach ($from === null ? $pending : [] as $other => $count) {
                if ($count > 0) {
                    $from = $other;
                    break;
                }
            }

            if ($from !== null) {
                $pending[$from]--;
            }

            $left--;
            $result[] = ['identifies' => true, 'from' => $from];
        }

        return $result;
    }

    /**
     * Caravans written for head already counted: they stop being uncaravaned, not received again,
     * and each one takes a pending head as identificationOf() tells.
     *
     * @param list<int> $caravanBatchIds the batch each caravan goes to, in order
     * @return list<array{batch_id: int, from: int}> caravans that took a head pending in another batch
     */
    public function identify(array $caravanBatchIds): array
    {
        $corrections = [];

        foreach ($this->identificationOf($caravanBatchIds) as $index => $step) {
            if (!$step['identifies']) {
                continue;
            }

            $this->uncaravanedHeadCount--;

            if ($step['from'] === null) {
                continue;
            }

            $this->takePending($step['from'], 1);

            if ($step['from'] !== $caravanBatchIds[$index]) {
                $corrections[] = ['batch_id' => $caravanBatchIds[$index], 'from' => $step['from']];
            }
        }

        $this->changed = true;

        return $corrections;
    }

    /**
     * Head received without caravan that will never get one (dead or lost before the chute wrote
     * it): they stop being pending in their batch, and stay received.
     *
     * @throws EntryOrderDomainException
     */
    public function writeOff(int $batchId, int $head): void
    {
        $pending = $this->pendingByBatch[$batchId] ?? 0;

        if ($head < 1 || $head > $pending) {
            throw EntryOrderDomainException::invalid(
                "El DTE {$this->dteNumber} tiene {$pending} cabezas por identificar en ese lote: no se pueden dar de baja {$head}.",
                'PENDING_WRITE_OFF_EXCEEDS',
                'head_count'
            );
        }

        $this->takePending($batchId, $head);
        $this->uncaravanedHeadCount -= $head;
        $this->writtenOffHeadCount += $head;
        $this->changed = true;
    }

    private function takePending(int $batchId, int $head): void
    {
        $this->pendingByBatch[$batchId] = ($this->pendingByBatch[$batchId] ?? 0) - $head;

        if ($this->pendingByBatch[$batchId] <= 0) {
            unset($this->pendingByBatch[$batchId]);
        }
    }

    /**
     * @return array<int, int> batch id → head pending identification there
     */
    public function getPendingByBatch(): array
    {
        return $this->pendingByBatch;
    }

    public function batchName(int $batchId): ?string
    {
        return $this->batchNames[$batchId] ?? null;
    }

    /**
     * The day its head were confirmed by count: the entry day of the caravans that identify them.
     */
    public function getHeadsReceivedAt(): ?string
    {
        return $this->headsReceivedAt;
    }

    public function getWrittenOffHeadCount(): int
    {
        return $this->writtenOffHeadCount;
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
