<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\FiscalDiscrepancyEntity;
use App\Core\ValueObjects\DiscrepancyFilter;

/** Delivery-note discrepancies of a company (delivery C). Every method filters by company. */
interface IFiscalDiscrepancyRepository
{
    /** @param list<FiscalDiscrepancyEntity> $discrepancies */
    public function createMany(array $discrepancies): void;

    /** @return list<FiscalDiscrepancyEntity> newest first */
    public function search(DiscrepancyFilter $filter, int $companyId): array;

    public function find(int $id, int $companyId): ?FiscalDiscrepancyEntity;

    public function save(FiscalDiscrepancyEntity $discrepancy): void;

    /** The invoice was voided: its discrepancies no longer stand. */
    public function cancelForInvoice(int $invoiceId, int $companyId): void;

    /** @return array{open: int, resolved: int, cancelled: int} */
    public function countsFor(int $documentId, int $companyId): array;
}
