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
        private array $batches = [],
        private int $caravansCount = 0
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
}
