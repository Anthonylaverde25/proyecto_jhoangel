<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\Caravans\RegisterAbortionDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Enums\CategoryChangeCause;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ICaravanRepository;
use App\Models\GestationLossReason;

final class RegisterAbortionUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly RegisterGestationLossUseCase $registerGestationLoss
    ) {
    }

    /**
     * Registers an abortion for a pregnant female and optionally promotes a vaquillona to cow.
     *
     * @throws DomainException
     */
    public function __invoke(RegisterAbortionDTO $dto): CaravanEntity
    {
        $caravan = $this->caravanRepository->findById($dto->caravanId);
        if ($caravan === null || $caravan->getCompanyId() !== $dto->companyId) {
            throw new DomainException("La caravana con ID {$dto->caravanId} no existe o no pertenece a la empresa.");
        }

        // AB3: Cannot register abortion on dead or exited animal
        if ($caravan->isDead()) {
            throw new DomainException("El animal está muerto.");
        }
        if ($caravan->getExit() !== null) {
            throw new DomainException("El animal ya egresó del establecimiento.");
        }

        // AB1: Must have active gestation
        $activeGestation = $caravan->getActiveGestation();
        if ($activeGestation === null) {
            throw new DomainException("La caravana no tiene un proceso de gestación activo.");
        }

        // AB2: Date not future and not before gestation start date
        $today = date('Y-m-d');
        if ($dto->abortedOn > $today) {
            throw new DomainException("La fecha de aborto no puede ser futura.");
        }

        $gestationStart = $activeGestation->getStartDate();
        if ($gestationStart !== null && $dto->abortedOn < $gestationStart) {
            throw new DomainException("La fecha de aborto no puede ser anterior al inicio de la gestación ({$gestationStart}).");
        }

        // AB4: "Pasa a vaca" only allowed if female is VAQUILLONA
        $categoryCode = strtoupper(trim((string) $caravan->getCategoryCode()));
        if ($dto->becomesCow && $categoryCode !== 'VAQUILLONA') {
            throw new DomainException("Sólo una vaquillona pasa a vaca.");
        }

        // Resolve loss reason for ABORTION
        $lossReason = GestationLossReason::where('code', 'ABORTION')
            ->where(function ($query) use ($dto) {
                $query->where('company_id', $dto->companyId)
                    ->orWhereNull('company_id');
            })
            ->first();

        if ($lossReason === null) {
            // Fallback: create or retrieve any active ABORTION reason
            $lossReason = GestationLossReason::firstOrCreate(
                ['code' => 'ABORTION', 'company_id' => $dto->companyId],
                ['name' => 'Aborto', 'description' => 'Pérdida gestacional espontánea', 'is_active' => true]
            );
        }

        return ($this->registerGestationLoss)(
            caravanId: $dto->caravanId,
            lossReasonId: (int) $lossReason->id,
            lossNotes: $dto->notes,
            lossDate: $dto->abortedOn,
            promoteToCow: $dto->becomesCow,
            fromBirthOrder: false,
            actionUserId: $dto->userId,
            promotionCause: CategoryChangeCause::ABORTION_AS_CALVING
        );
    }
}

