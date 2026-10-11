<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\DestinationSexScope;

/**
 * One destination rule of an entry order: the own batch the animals of a category line go to —
 * all of them, or only one sex. A received caravan is created there directly.
 */
final class EntryOrderDestinationEntity
{
    public function __construct(
        private readonly int $categoryPosition,
        private readonly DestinationSexScope $sexScope,
        private readonly int $batchId,
        private readonly ?string $batchName = null
    ) {
    }

    public function getCategoryPosition(): int
    {
        return $this->categoryPosition;
    }

    public function getSexScope(): DestinationSexScope
    {
        return $this->sexScope;
    }

    public function getBatchId(): int
    {
        return $this->batchId;
    }

    public function getBatchName(): ?string
    {
        return $this->batchName;
    }

    public function withPosition(int $position, DestinationSexScope $scope): self
    {
        return new self($position, $scope, $this->batchId, $this->batchName);
    }
}
