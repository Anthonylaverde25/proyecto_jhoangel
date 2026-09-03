<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\DTOs\Veterinary\ResolveVeterinaryDiagnosisDTO;
use App\Core\Entities\BullHealthEvaluationEntity;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBullHealthEvaluationRepository;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IVeterinaryDiagnosisRepository;
use App\Core\Services\BullHealthEvaluationEngine;
use DateTimeImmutable;

final class ResolveVeterinaryDiagnosisUseCase
{
    public function __construct(
        private readonly IVeterinaryDiagnosisRepository $diagnosisRepository,
        private readonly IBullHealthEvaluationRepository $bullHealthRepository,
        private readonly ICompanyContext $companyContext,
        private readonly BullHealthEvaluationEngine $engine
    ) {
    }

    public function __invoke(ResolveVeterinaryDiagnosisDTO $dto): bool
    {
        $companyId = $this->companyContext->getCompanyId() ?? 1;

        $diagnosis = $this->diagnosisRepository->findById($dto->diagnosisId);
        if (!$diagnosis) {
            throw new DomainException("El diagnóstico ID {$dto->diagnosisId} no existe.");
        }

        $resolutionDate = new DateTimeImmutable($dto->resolutionDate ?? date('Y-m-d'));
        $resolved = $this->diagnosisRepository->resolve($dto->diagnosisId, $resolutionDate, $dto->notes);

        if (!$resolved) {
            return false;
        }

        // Reactively recompute bull's reproductive aptitude if caravan has bull health record
        $caravanId = $diagnosis->getCaravanId();
        $bullHealth = $this->bullHealthRepository->findByCaravanId($caravanId, $companyId);
        if ($bullHealth) {
            $activeDiagnoses = $this->diagnosisRepository->findActiveByCaravanId($caravanId);
            $newStatus = $this->engine->computeAptitude(
                $bullHealth->getScrotalCircumferenceCm(),
                $bullHealth->getBodyConditionScore(),
                $bullHealth->getAplomoNotes(),
                $activeDiagnoses
            );

            $updatedBullHealth = new BullHealthEvaluationEntity(
                id: $bullHealth->getId(),
                companyId: $companyId,
                caravanId: $caravanId,
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

        return true;
    }
}
