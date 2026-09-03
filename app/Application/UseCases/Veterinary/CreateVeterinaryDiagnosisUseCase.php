<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\DTOs\Veterinary\CreateVeterinaryDiagnosisDTO;
use App\Core\Entities\BullHealthEvaluationEntity;
use App\Core\Entities\VeterinaryDiagnosisEntity;
use App\Core\Enums\DiagnosisStatus;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBullHealthEvaluationRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IVeterinaryDiagnosisRepository;
use App\Core\Services\BullHealthEvaluationEngine;
use DateTimeImmutable;

final class CreateVeterinaryDiagnosisUseCase
{
    public function __construct(
        private readonly IVeterinaryDiagnosisRepository $diagnosisRepository,
        private readonly IBullHealthEvaluationRepository $bullHealthRepository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICompanyContext $companyContext,
        private readonly BullHealthEvaluationEngine $engine
    ) {
    }

    public function __invoke(CreateVeterinaryDiagnosisDTO $dto): VeterinaryDiagnosisEntity
    {
        $companyId = $this->companyContext->getCompanyId() ?? 1;

        $caravan = $this->caravanRepository->findById($dto->caravanId);
        if (!$caravan) {
            throw new DomainException("La caravana ID {$dto->caravanId} no existe.");
        }

        $diagnosis = new VeterinaryDiagnosisEntity(
            id: null,
            companyId: $companyId,
            caravanId: $dto->caravanId,
            pathogenId: $dto->pathogenId,
            veterinarianId: $dto->veterinarianId,
            diagnosisDate: new DateTimeImmutable($dto->diagnosisDate ?? date('Y-m-d')),
            status: DiagnosisStatus::from($dto->status),
            resolutionDate: null,
            treatmentNotes: $dto->treatmentNotes,
            sourceContext: $dto->sourceContext
        );

        $savedDiagnosis = $this->diagnosisRepository->save($diagnosis);

        // If the animal is a bull with physical health records, reactively recompute its aptitude
        $bullHealth = $this->bullHealthRepository->findByCaravanId($dto->caravanId, $companyId);
        if ($bullHealth) {
            $activeDiagnoses = $this->diagnosisRepository->findActiveByCaravanId($dto->caravanId);
            $newStatus = $this->engine->computeAptitude(
                $bullHealth->getScrotalCircumferenceCm(),
                $bullHealth->getBodyConditionScore(),
                $bullHealth->getAplomoNotes(),
                $activeDiagnoses
            );

            $updatedBullHealth = new BullHealthEvaluationEntity(
                id: $bullHealth->getId(),
                companyId: $companyId,
                caravanId: $dto->caravanId,
                lastEvaluationDate: $bullHealth->getLastEvaluationDate(),
                aplomoNotes: $bullHealth->getAplomoNotes(),
                scrotalCircumferenceCm: $bullHealth->getScrotalCircumferenceCm(),
                bodyConditionScore: $bullHealth->getBodyConditionScore(),
                libido: $bullHealth->getLibido(),
                status: $newStatus,
                observations: $bullHealth->getObservations(),
                caravanNumber: $bullHealth->getCaravanNumber(),
                activeDiagnoses: $activeDiagnoses
            );

            $this->bullHealthRepository->save($updatedBullHealth);
        }

        return $savedDiagnosis;
    }
}
