<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/** The day an animal left the company and the exit order it left by (the "Vendida" mark). */
final readonly class ExitRecord
{
    public function __construct(
        public string $date,
        public string $orderCode,
        public ?int $orderId = null,
    ) {
    }
}
