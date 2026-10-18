<?php

declare(strict_types=1);

namespace App\Application\UseCases\DeliveryNotes;

use App\Core\Entities\FiscalDiscrepancyEntity;
use App\Core\Interfaces\IFiscalDiscrepancyRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A difference between a delivery note and its invoice is settled with the provider, outside the
 * system (DC8): here it is closed with how it was settled. Nothing changes in stock or cost.
 */
final class ResolveFiscalDiscrepancyUseCase
{
    public function __construct(
        private readonly IFiscalDiscrepancyRepository $discrepancies,
    ) {
    }

    public function __invoke(int $id, string $resolution, int $companyId, int $userId): FiscalDiscrepancyEntity
    {
        $discrepancy = $this->discrepancies->find($id, $companyId)
            ?? throw new ModelNotFoundException("Fiscal discrepancy {$id} not found.");

        $discrepancy->resolve($resolution, $userId, now()->toIso8601String());
        $this->discrepancies->save($discrepancy);

        return $this->discrepancies->find($id, $companyId) ?? $discrepancy;
    }
}
