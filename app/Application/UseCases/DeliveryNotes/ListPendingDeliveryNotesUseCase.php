<?php

declare(strict_types=1);

namespace App\Application\UseCases\DeliveryNotes;

use App\Core\Interfaces\IFiscalDocumentRepository;

/**
 * "Remitos a facturar" (DC12): what arrived with a delivery note and has no invoice yet. With a
 * provider, it is the notice of a direct invoice (DC9).
 */
final class ListPendingDeliveryNotesUseCase
{
    public function __construct(
        private readonly IFiscalDocumentRepository $documents,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(?int $providerId, int $companyId): array
    {
        return $this->documents->pendingDeliveryNotes($providerId, $companyId);
    }
}
