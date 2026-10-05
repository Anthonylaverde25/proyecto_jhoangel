<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\IBirthOrderRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use Illuminate\Support\Facades\DB;

/**
 * Closes the current gestation of a female as lost: an abortion, a reabsorption, a calf born dead…
 *
 * When the loss is registered outside the PAR-01 (from Monitoreo Gestacional) and the female is
 * still waiting to calve in an open birth order, her line is closed with the real reason in the
 * same transaction, and the order recalculated: an abortion does not leave the order waiting for a
 * calving that will never happen. The PAR-01 passes `fromBirthOrder`, since it records the line
 * itself.
 */
final class RegisterGestationLossUseCase
{
    public const HISTORY_ORIGIN = 'GESTATION_LOSS';

    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IAnimalCategoryRepository $animalCategoryRepository,
        private readonly IBirthOrderRepository $birthOrderRepository
    ) {
    }

    /**
     * @param bool $promoteToCow true for a full-term calving whose calf was born dead: the female
     *        calved, so she is a cow from now on, as she would be with a live calf.
     * @param bool $fromBirthOrder true when the PAR-01 registers it: the order line is its business.
     */
    public function __invoke(
        int $caravanId,
        int $lossReasonId,
        ?string $lossNotes,
        string $lossDate,
        bool $promoteToCow = false,
        bool $fromBirthOrder = false,
        ?int $actionUserId = null
    ): CaravanEntity {
        return DB::transaction(function () use ($caravanId, $lossReasonId, $lossNotes, $lossDate, $promoteToCow, $fromBirthOrder, $actionUserId) {
            $caravan = $this->caravanRepository->findById($caravanId);
            if ($caravan === null) {
                throw new DomainException("La caravana con ID {$caravanId} no existe.");
            }

            $activeGestation = $caravan->getActiveGestation();
            if ($activeGestation === null) {
                throw new DomainException("La caravana no tiene un proceso de gestación activo.");
            }

            $gestationId = $activeGestation->getId();

            // Close the gestation as unsuccessful (success = false)
            $activeGestation->closeGestation(
                success: false,
                endDate: $lossDate,
                notes: "Gestation ended with loss.",
                lossReasonId: $lossReasonId,
                lossNotes: $lossNotes
            );

            // Update female details to empty
            $reproductiveDetails = $caravan->getReproductiveDetails();
            $arrivalCategory = $reproductiveDetails !== null
                ? $reproductiveDetails->getArrivalCategory()
                : AnimalCategory::VACA;

            $caravan->recordFemaleDetails(new FemaleReproductiveDetails(true, $arrivalCategory));

            if ($promoteToCow) {
                $cowCategoryId = $this->animalCategoryRepository->findByCode('VACA')?->getId();

                if ($cowCategoryId !== null) {
                    $caravan->setCategoryId($cowCategoryId);
                }
            }

            $saved = $this->caravanRepository->save($caravan);

            if (!$fromBirthOrder && $gestationId !== null && $caravan->getCompanyId() !== null) {
                $this->closeOpenBirthOrderLine($caravanId, $gestationId, (int) $caravan->getCompanyId(), $lossReasonId, $lossDate, $actionUserId);
            }

            return $saved;
        });
    }

    /**
     * R4: the line of a birth order still waiting for this gestation is closed with the real reason.
     */
    private function closeOpenBirthOrderLine(int $motherId, int $gestationId, int $companyId, int $lossReasonId, string $lossDate, ?int $actionUserId): void
    {
        $order = $this->birthOrderRepository->findOpenLineForGestation($motherId, $gestationId, $companyId);

        if ($order === null) {
            return;
        }

        $reasonCode = $this->birthOrderRepository->lossReasonCodeById($lossReasonId, $companyId) ?? 'OTHER';
        $line = $order->resolveByExternalLoss($motherId, $reasonCode, $lossDate, new \DateTimeImmutable());

        if ($line === null) {
            return;
        }

        $this->birthOrderRepository->save($order, $actionUserId, null, [
            'origin' => self::HISTORY_ORIGIN,
            'mother_caravan_id' => $motherId,
            'loss_reason_code' => $reasonCode,
            'loss_date' => substr($lossDate, 0, 10),
            'was_overdue' => $line->getOverdueReportedAt() !== null,
            'pending' => $order->pendingCount(),
        ]);
    }
}
