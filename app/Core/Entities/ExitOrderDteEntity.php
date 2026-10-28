<?php

declare(strict_types=1);

namespace App\Core\Entities;

/** A DTE covering the trip of an exit: a quantity document (number, date, head), not per animal. */
final class ExitOrderDteEntity
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $dteNumber,
        public readonly string $dteDate,
        public readonly int $headCount,
        public readonly ?int $loadedByUserId = null,
        public readonly ?string $observations = null,
        public readonly ?\DateTimeInterface $createdAt = null,
    ) {
    }
}
