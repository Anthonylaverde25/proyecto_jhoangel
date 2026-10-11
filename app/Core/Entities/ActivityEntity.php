<?php

declare(strict_types=1);

namespace App\Core\Entities;

class ActivityEntity
{
    /**
     * @param BatchEntity[] $batches
     */
    public function __construct(
        private ?int $id,
        private string $name,
        private string $code,
        private bool $isEnabled = true,
        private bool $isFinal = false,
        private bool $isInitial = false,
        private int $sortOrder = 1,
        private array $batches = [],
        private int $caravansCount = 0,
        private int $pendingIdentificationCount = 0
    ) {
    }

    public function getBatches(): array
    {
        return $this->batches;
    }

    public function setBatches(array $batches): void
    {
        $this->batches = $batches;
        $this->caravansCount = array_sum(array_map(
            static fn(BatchEntity $batch): int => $batch->getCaravansCount() ?? 0,
            $batches
        ));
        $this->pendingIdentificationCount = array_sum(array_map(
            static fn(BatchEntity $batch): int => $batch->getPendingIdentificationCount() ?? 0,
            $batches
        ));
    }

    /**
     * Head received by count in its batches, their caravans still to write.
     */
    public function getPendingIdentificationCount(): int
    {
        return $this->pendingIdentificationCount;
    }

    /**
     * Caravans plus head pending identification: the head the activity holds.
     */
    public function getHeadCount(): int
    {
        return $this->caravansCount + $this->pendingIdentificationCount;
    }

    public function getCaravansCount(): int
    {
        return $this->caravansCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function isFinal(): bool
    {
        return $this->isFinal;
    }

    public function isInitial(): bool
    {
        return $this->isInitial;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
}
