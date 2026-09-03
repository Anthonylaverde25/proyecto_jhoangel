<?php

declare(strict_types=1);

namespace App\Application\UseCases\PreService;

use App\Application\DTOs\PreService\RegisterBullHealthEvaluationDTO;
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

final class RegisterBullHealthEvaluationUseCase
{
    public function __construct(
        private readonly IBullHealthEvaluationRepository $bullHealthRepository,
        private readonly IVeterinaryDiagnosisRepository $diagnosisRepository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICompanyContext $companyContext,
        private readonly BullHealthEvaluationEngine $engine
    ) {
    }

    public function __invoke(RegisterBullHealthEvaluationDTO $dto): BullHealthEvaluationEntity
    {
        $companyId = $this->companyContext->getCompanyId() ?? 1;

        // 1. Verify caravan exists
        $caravan = $this->caravanRepository->findById($dto->caravanId);
        if (!$caravan) {
            throw new DomainException("La caravana ID {$dto->caravanId} no existe.");
        }

        // 2. If a new diagnosis was reported in manga, record it
        if ($dto->diagnosis) {
            $diagnosisEntity = new VeterinaryDiagnosisEntity(
                id: null,
                companyId: $companyId,
                caravanId: $dto->caravanId,
                pathogenId: $dto->diagnosis->pathogenId,
                veterinarianId: $dto->diagnosis->veterinarianId,
                diagnosisDate: new DateTimeImmutable($dto->diagnosis->diagnosisDate ?? date('Y-m-d')),
                status: DiagnosisStatus::from($dto->diagnosis->status),
                resolutionDate: null,
                treatmentNotes: $dto->diagnosis->treatmentNotes,
                sourceContext: $dto->diagnosis->sourceContext
            );

            $this->diagnosisRepository->save($diagnosisEntity);
        }

        // 3. Retrieve all active diagnoses for this bull
        $activeDiagnoses = $this->diagnosisRepository->findActiveByCaravanId($dto->caravanId);

        // 4. Compute reproductive aptitude using domain rules (Carrillo)
        $status = $this->engine->computeAptitude(
            $dto->scrotalCircumferenceCm,
            $dto->bodyConditionScore,
            $dto->aplomoNotes,
            $activeDiagnoses
        );

        // 5. Build evaluation entity
        $evaluation = new BullHealthEvaluationEntity(
            id: null,
            companyId: $companyId,
            caravanId: $dto->caravanId,
            lastEvaluationDate: $dto->lastEvaluationDate ? new DateTimeImmutable($dto->lastEvaluationDate) : new DateTimeImmutable(),
            aplomoNotes: $dto->aplomoNotes,
            scrotalCircumferenceCm: $dto->scrotalCircumferenceCm,
            bodyConditionScore: $dto->bodyConditionScore,
            libido: $dto->libido,
            status: $status,
            observations: $dto->observations,
            caravanNumber: (string) $caravan->getIdentification()->getValue(),
            activeDiagnoses: $activeDiagnoses
        );

        // 6. Persist and return
        return $this->bullHealthRepository->save($evaluation);
    }
}
