<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\RegisterGestationDiagnosisDTO;
use App\Application\Services\CaravanBatchRelocation;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Enums\FemaleReproductiveVerdict;
use App\Core\Enums\GestationStage;
use App\Core\Enums\ServiceOrderIncidentType;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderIncidentRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use App\Core\ValueObjects\SireEntry;
use Illuminate\Support\Facades\DB;

/**
 * Registers the pregnancy diagnosis of a female for a service order.
 *
 * The verdict goes to the round of its day (created with the first verdict of that day) and
 * becomes the current one of the female; the previous verdict stays in the history. Stage and
 * months are what was declared: nothing is defaulted.
 */
final class RegisterGestationDiagnosisUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly IServiceOrderIncidentRepository $incidentRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly CaravanBatchRelocation $relocation
    ) {
    }

    /**
     * @throws DomainException
     */
    public function __invoke(RegisterGestationDiagnosisDTO $dto, int $companyId): CaravanEntity
    {
        return DB::transaction(fn (): CaravanEntity => $this->register($dto, $companyId));
    }

    /**
     * @throws DomainException
     */
    private function register(RegisterGestationDiagnosisDTO $dto, int $companyId): CaravanEntity
    {
        $caravan = $this->caravanRepository->findById($dto->caravanId);
        if ($caravan === null) {
            throw new DomainException('Caravan not found.');
        }

        if ($caravan->getCompanyId() !== $companyId) {
            throw new DomainException('Caravan does not belong to the active company.');
        }

        $serviceOrder = $this->serviceOrderRepository->findById($dto->serviceOrderId, $companyId);
        if ($serviceOrder === null) {
            throw new DomainException('Service order not found.');
        }

        $this->assertOrderAcceptsDiagnosis($serviceOrder);

        $current = $this->serviceOrderRepository->findFemaleVerdict($dto->serviceOrderId, $dto->caravanId);
        if ($current === null) {
            throw new DomainException('This caravan does not belong to the selected service order.');
        }

        $identification = $caravan->getIdentification()->getValue();
        if ($current['reproductive_status'] === FemaleReproductiveVerdict::EXITED->value) {
            throw new DomainException("{$identification} salió de la orden (muerte o venta): no se diagnostica.");
        }

        // AI without repaso (semen S-B, IA12): nobody served a female never inseminated.
        if ($serviceOrder->isInsemination() && !$serviceOrder->hadRepasoBulls() && !$this->wasInseminated($serviceOrder, $dto->caravanId)) {
            throw new DomainException("{$identification} no tiene inseminación registrada en la orden {$serviceOrder->getCode()}: cargala antes del tacto.");
        }

        $diagnosisDate = $this->resolveDiagnosisDate($dto->diagnosisDate, $serviceOrder);
        $roundId = $this->serviceOrderRepository->findOrCreateDiagnosisRound($dto->serviceOrderId, $companyId, $diagnosisDate);
        $category = $caravan->getReproductiveDetails()?->getArrivalCategory() ?? AnimalCategory::VACA;

        if ($dto->isPregnant) {
            return $this->registerPregnant($dto, $companyId, $caravan, $serviceOrder, $roundId, $diagnosisDate, $category);
        }

        return $this->registerEmpty($dto, $companyId, $caravan, $roundId, $diagnosisDate, $category);
    }

    private function registerPregnant(
        RegisterGestationDiagnosisDTO $dto,
        int $companyId,
        CaravanEntity $caravan,
        ServiceOrderEntity $serviceOrder,
        int $roundId,
        string $diagnosisDate,
        AnimalCategory $category
    ): CaravanEntity {
        [$stage, $months] = $this->resolveGestationDetails($dto->gestationStage, $dto->gestationMonths);
        $previousVerdict = $this->serviceOrderRepository->findLatestDiagnosedVerdict($dto->serviceOrderId, $dto->caravanId);
        $caravan->recordFemaleDetails(new FemaleReproductiveDetails(false, $category));

        $activeGestation = $caravan->getActiveGestation();
        if ($activeGestation !== null && $activeGestation->getServiceOrderId() === $dto->serviceOrderId) {
            // A re-check of the same pregnancy: it keeps its sires and states its stage again.
            $activeGestation->restage($stage, $months);
            if ($dto->confirmedSireId !== null) {
                $activeGestation->confirmSire($dto->confirmedSireId);
            }
        } else {
            $caravan->startNewGestation($diagnosisDate, $stage, $months, $dto->serviceOrderId);
            $this->assignSires($caravan, $serviceOrder, $dto->confirmedSireId);
        }

        $saved = $this->caravanRepository->save($caravan);

        $this->serviceOrderRepository->recordFemaleVerdict(
            orderId: $dto->serviceOrderId,
            companyId: $companyId,
            femaleId: $dto->caravanId,
            roundId: $roundId,
            verdict: FemaleReproductiveVerdict::PREGNANT,
            diagnosedAt: $diagnosisDate,
            userId: $dto->userId,
            gestationId: $saved->getActiveGestation()?->getId(),
            gestationStage: $stage?->value,
            gestationMonths: $months
        );

        // Found empty before, moved out of the service batch, pregnant now: recorded, and reported.
        if ($previousVerdict === FemaleReproductiveVerdict::EMPTY && $saved->getBatchId() !== $serviceOrder->getBatchId()) {
            $this->raisePregnantOutsideServiceBatch($dto, $companyId, $saved, $serviceOrder, $diagnosisDate);
        }

        return $saved;
    }

    private function registerEmpty(
        RegisterGestationDiagnosisDTO $dto,
        int $companyId,
        CaravanEntity $caravan,
        int $roundId,
        string $diagnosisDate,
        AnimalCategory $category
    ): CaravanEntity {
        $caravan->recordFemaleDetails(new FemaleReproductiveDetails(true, $category));

        $activeGestation = $caravan->getActiveGestation();
        if ($activeGestation !== null) {
            $activeGestation->closeGestation(
                success: false,
                endDate: $diagnosisDate,
                notes: 'Marked empty via gestation diagnosis'
            );
        }

        $saved = $this->caravanRepository->save($caravan);

        $this->serviceOrderRepository->recordFemaleVerdict(
            orderId: $dto->serviceOrderId,
            companyId: $companyId,
            femaleId: $dto->caravanId,
            roundId: $roundId,
            verdict: FemaleReproductiveVerdict::EMPTY,
            diagnosedAt: $diagnosisDate,
            userId: $dto->userId
        );

        if ($dto->emptyDestinationBatchId !== null && $dto->emptyDestinationBatchId !== $saved->getBatchId()) {
            // Leaving the service batch at the chute ends her exposure that day, which is what
            // makes the empty verdict final.
            $this->relocation->relocate(
                [$dto->caravanId],
                $companyId,
                $dto->emptyDestinationBatchId,
                $diagnosisDate,
                "Relocated to Batch ID: {$dto->emptyDestinationBatchId} after empty pregnancy diagnosis (from Batch ID: {$saved->getBatchId()})"
            );

            return $this->caravanRepository->findById($dto->caravanId) ?? $saved;
        }

        return $saved;
    }

    private function wasInseminated(ServiceOrderEntity $serviceOrder, int $femaleId): bool
    {
        foreach ($serviceOrder->getFemaleExposures() as $female) {
            if ((int) $female['female_caravan_id'] === $femaleId) {
                return ($female['last_insemination_on'] ?? null) !== null;
            }
        }

        return false;
    }

    private function assignSires(CaravanEntity $caravan, ServiceOrderEntity $serviceOrder, ?int $confirmedSireId): void
    {
        $activeGestation = $caravan->getActiveGestation();
        if ($activeGestation === null) {
            return;
        }

        $maleIds = $serviceOrder->getMaleCaravanIds();
        foreach ($maleIds as $maleId) {
            // With a single bull in the order the sire is known; not in an AI order, where the
            // straws may have served her before the repaso bull (the semen sire comes in S-C).
            $isConfirmed = (count($maleIds) === 1 && !$serviceOrder->isInsemination())
                || ($confirmedSireId !== null && $maleId === $confirmedSireId);
            $activeGestation->addSire(new SireEntry($maleId, '', $isConfirmed));
        }
    }

    private function raisePregnantOutsideServiceBatch(
        RegisterGestationDiagnosisDTO $dto,
        int $companyId,
        CaravanEntity $caravan,
        ServiceOrderEntity $serviceOrder,
        string $diagnosisDate
    ): void {
        $batchId = $caravan->getBatchId();
        $batchName = $batchId !== null ? $this->batchRepository->findById($batchId)?->getName() : null;
        $identification = $caravan->getIdentification()->getValue();
        $where = $batchName !== null ? "el lote '{$batchName}'" : 'ningún lote';

        $this->incidentRepository->raise(
            companyId: $companyId,
            serviceOrderId: $dto->serviceOrderId,
            femaleCaravanId: $dto->caravanId,
            type: ServiceOrderIncidentType::PREGNANT_OUTSIDE_SERVICE_BATCH,
            detail: "{$identification} se diagnosticó preñada el " . date('d/m/Y', (int) strtotime($diagnosisDate))
                . " y está en {$where}, fuera del lote de servicio, adonde se la movió tras un diagnóstico de vacía.",
            metadata: [
                'diagnosis_date' => $diagnosisDate,
                'current_batch_id' => $batchId,
                'service_batch_id' => $serviceOrder->getBatchId(),
            ],
            raisedByUserId: $dto->userId
        );
    }

    /**
     * @throws DomainException
     */
    private function assertOrderAcceptsDiagnosis(ServiceOrderEntity $serviceOrder): void
    {
        if ($serviceOrder->acceptsDiagnosis()) {
            return;
        }

        if ($serviceOrder->getStatus() === ServiceOrderStatus::SUCCESS) {
            throw new DomainException(
                "La orden {$serviceOrder->getCode()} ya está cerrada con su diagnóstico. Una pérdida posterior se registra como pérdida de gestación."
            );
        }

        throw new DomainException("La orden {$serviceOrder->getCode()} no está en servicio: no admite diagnósticos.");
    }

    /**
     * The round day: today by default, never in the future nor before the service started.
     *
     * @throws DomainException
     */
    private function resolveDiagnosisDate(?string $date, ServiceOrderEntity $serviceOrder): string
    {
        $day = substr($date ?? date('Y-m-d'), 0, 10);

        if ($day > date('Y-m-d')) {
            throw new DomainException('La fecha de la ronda de diagnóstico no puede ser futura.');
        }

        $start = $serviceOrder->getActualStartDate();
        if ($start !== null && $day < substr($start, 0, 10)) {
            throw new DomainException('La fecha de la ronda de diagnóstico es anterior al inicio del servicio.');
        }

        return $day;
    }

    /**
     * Months (a scan) determine the stage; a stage alone keeps no months; nothing declared
     * leaves both null.
     *
     * @return array{0: ?GestationStage, 1: ?float}
     */
    private function resolveGestationDetails(?string $stage, ?float $months): array
    {
        if ($months !== null) {
            return [GestationStage::fromMonths($months), $months];
        }

        if ($stage !== null && $stage !== '') {
            return [GestationStage::from($stage), null];
        }

        return [null, null];
    }
}
