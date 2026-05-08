<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\CaravanEntity;
use App\Application\UseCases\Caravans\RecordCaravanMovementUseCase;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\Interfaces\IFarmRepository;

final class CaravanTraceabilityService
{
    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly IFarmRepository $farmRepository,
        private readonly ICompanyRepository $companyRepository,
        private readonly RecordCaravanMovementUseCase $recordMovement
    ) {}

    public function recordInitialArrival(CaravanEntity $caravan, int $activeCompanyId): void
    {
        $caravanId = $caravan->getId();
        if (!$caravanId) return;

        // 1. Trazabilidad: Origen (Farm)
        if ($caravan->getBatchId()) {
            $batch = $this->batchRepository->findById($caravan->getBatchId());
            if ($batch && $batch->getFarmId()) {
                $farm = $this->farmRepository->findById($batch->getFarmId());
                if ($farm && $farm->getRenspa()) {
                    $this->recordMovement->execute($caravanId, $farm->getRenspa(), 'ORIGIN', "Ingreso inicial desde Farm: {$farm->getName()}");
                }
            }
        }

        // 2. Trazabilidad: Entrada (Current Company)
        $currentCompany = $this->companyRepository->findById($activeCompanyId);
        if ($currentCompany && $currentCompany->getRenspa()) {
            $this->recordMovement->execute($caravanId, $currentCompany->getRenspa(), 'ENTRY', "Entrada a empresa: {$currentCompany->getName()}");
        }
    }

    public function recordTransfer(CaravanEntity $caravan, int $oldCompanyId, int $newCompanyId): void
    {
        $caravanId = $caravan->getId();
        if (!$caravanId) return;

        // Registrar Salida de la vieja empresa
        $oldCompany = $this->companyRepository->findById($oldCompanyId);
        if ($oldCompany && $oldCompany->getRenspa()) {
            $this->recordMovement->execute($caravanId, $oldCompany->getRenspa(), 'EXIT', "Transferencia a empresa ID: {$newCompanyId}");
        }

        // Registrar Entrada en la nueva empresa
        $newCompany = $this->companyRepository->findById($newCompanyId);
        if ($newCompany && $newCompany->getRenspa()) {
            $this->recordMovement->execute($caravanId, $newCompany->getRenspa(), 'TRANSFER', "Transferencia recibida desde empresa ID: {$oldCompanyId}");
        }
    }
}
