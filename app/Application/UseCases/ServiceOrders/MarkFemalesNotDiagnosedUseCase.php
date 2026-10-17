<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\MarkFemalesNotDiagnosedDTO;
use App\Core\Enums\FemaleReproductiveVerdict;
use App\Core\Enums\NotDiagnosedReason;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * The producer declares that some females of the order will have no diagnosis, with a reason.
 *
 * Only once the bulls are gone (or while closing the order, which ends the service): with the
 * bulls still in the batch there is nothing to give up on yet. The declaration stays in the
 * history of its round, and a later diagnosis still replaces it.
 */
final class MarkFemalesNotDiagnosedUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $serviceOrderRepository
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(MarkFemalesNotDiagnosedDTO $dto): int
    {
        $order = $this->serviceOrderRepository->findById($dto->serviceOrderId, $dto->companyId);
        if ($order === null) {
            throw ServiceOrderDomainException::domainError('La orden de servicio no existe.');
        }

        $allowed = $order->getStatus() === ServiceOrderStatus::BULLS_WITHDRAWN
            || ($dto->duringClosing && $order->getStatus() === ServiceOrderStatus::APPROVED);
        if (!$allowed) {
            throw ServiceOrderDomainException::domainError(
                $order->getStatus() === ServiceOrderStatus::APPROVED
                    ? 'Con los toros en el lote todavía no se declara "sin diagnóstico": se hace al retirar la torada o al cerrar la orden.'
                    : "La orden {$order->getCode()} no admite cambios de diagnóstico."
            );
        }

        if ($dto->females === []) {
            return 0;
        }

        return DB::transaction(function () use ($dto): int {
            $roundId = $this->serviceOrderRepository->findOrCreateDiagnosisRound($dto->serviceOrderId, $dto->companyId, $dto->roundDate);

            foreach ($dto->females as $female) {
                $reason = NotDiagnosedReason::tryFrom($female['reason']);
                if ($reason === null) {
                    throw ServiceOrderDomainException::domainError("Motivo de \"sin diagnóstico\" desconocido: {$female['reason']}.");
                }

                if ($reason === NotDiagnosedReason::OTHER && $female['notes'] === null) {
                    throw ServiceOrderDomainException::domainError('Con el motivo "Otro" hay que escribir una nota.');
                }

                $current = $this->serviceOrderRepository->findFemaleVerdict($dto->serviceOrderId, $female['female_caravan_id']);
                if ($current === null) {
                    throw ServiceOrderDomainException::domainError("La caravana {$female['female_caravan_id']} no pertenece a la orden.");
                }

                if (in_array($current['reproductive_status'], [FemaleReproductiveVerdict::PREGNANT->value, FemaleReproductiveVerdict::EXITED->value], true)) {
                    throw ServiceOrderDomainException::domainError(
                        'Sólo un vientre sin diagnóstico final puede declararse "sin diagnóstico".'
                    );
                }

                $this->serviceOrderRepository->recordFemaleVerdict(
                    orderId: $dto->serviceOrderId,
                    companyId: $dto->companyId,
                    femaleId: $female['female_caravan_id'],
                    roundId: $roundId,
                    verdict: FemaleReproductiveVerdict::NOT_DIAGNOSED,
                    diagnosedAt: $dto->roundDate,
                    userId: $dto->userId,
                    notDiagnosedReason: $reason,
                    notDiagnosedNotes: $female['notes']
                );
            }

            return count($dto->females);
        });
    }
}
