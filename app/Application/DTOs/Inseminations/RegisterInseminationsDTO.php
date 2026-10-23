<?php

declare(strict_types=1);

namespace App\Application\DTOs\Inseminations;

final readonly class RegisterInseminationsDTO
{
    /** @param list<InseminationRowDTO> $rows */
    public function __construct(
        public int $serviceOrderId,
        public int $companyId,
        public ?int $userId,
        public string $idempotencyKey,
        public array $rows,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(int $serviceOrderId, int $companyId, ?int $userId, array $data): self
    {
        return new self(
            $serviceOrderId,
            $companyId,
            $userId,
            (string) $data['idempotency_key'],
            array_map(fn (array $row) => InseminationRowDTO::fromArray($row), array_values($data['rows'] ?? [])),
        );
    }
}
