<?php

declare(strict_types=1);

namespace App\Application\DTOs\ExitOrders;

/**
 * "Registrar egreso" and the quick "Vender": an exit that already happened, born executed, with
 * its rows and, when in hand, its DTE.
 */
final readonly class RegisterExitDTO
{
    /** @param list<ExitLineDTO> $lines */
    public function __construct(
        public int $companyId,
        public ?int $userId,
        public int $buyerPartnerId,
        public string $exitDate,
        public array $lines,
        public ?ExitDteDTO $dte = null,
        public ?int $exitBatchId = null,
        public ?string $responsable = null,
        public ?string $observations = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, int $companyId, ?int $userId): self
    {
        return new self(
            $companyId,
            $userId,
            (int) $data['buyer_partner_id'],
            (string) $data['exit_date'],
            ExitLineDTO::listFromArray($data['lines'] ?? []),
            ExitDteDTO::fromArray($data['dte'] ?? null),
            isset($data['exit_batch_id']) ? (int) $data['exit_batch_id'] : null,
            $data['responsable'] ?? null,
            $data['observations'] ?? null,
        );
    }
}
