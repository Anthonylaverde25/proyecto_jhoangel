<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\CloseServiceOrderDTO;
use App\Application\Services\ServiceBullWithdrawal;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws the whole torada before the diagnosis.
 *
 * The service ends physically, but the order is not closed: it stays open (BULLS_WITHDRAWN) until
 * its pregnancy diagnosis is complete. Empty verdicts given while the bulls were still in the batch are no longer
 * final and go back to pending.
 */
final class CloseServiceOrderWithBullWithdrawalUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly ServiceBullWithdrawal $bullWithdrawal
    ) {
    }

    /**
     * @return array{order: ServiceOrderEntity, reopened_female_ids: int[]}
     *
     * @throws ServiceOrderDomainException
     */
    public function __invoke(CloseServiceOrderDTO $dto): array
    {
        $order = $this->serviceOrderRepository->findById($dto->serviceOrderId, $dto->companyId);
        if ($order === null) {
            throw ServiceOrderDomainException::domainError("La orden de servicio con ID {$dto->serviceOrderId} no existe.");
        }

        if ($order->getStatus() !== ServiceOrderStatus::APPROVED) {
            throw ServiceOrderDomainException::domainError(
                "Solo se puede retirar la torada de una orden en servicio (Estado actual: {$order->getStatus()->value})."
            );
        }

        return DB::transaction(function () use ($dto, $order): array {
            $bulls = $this->bullWithdrawal->withdraw(
                orderId: (int) $order->getId(),
                companyId: $dto->companyId,
                serviceBatchId: $order->getBatchId(),
                orderCode: $order->getCode(),
                withdrawalDate: $dto->withdrawalDate,
                bullDestinations: $dto->bullDestinations,
                defaultDestinationBatchId: $dto->defaultDestinationBatchId,
                observations: $dto->observations
            );

            $reopened = $this->serviceOrderRepository->reopenProvisionalEmpties((int) $order->getId());

            // Reloaded: the withdrawal changed the bulls and the exposures behind the entity.
            $fresh = $this->serviceOrderRepository->findById((int) $order->getId(), $dto->companyId);
            if ($fresh === null) {
                throw ServiceOrderDomainException::domainError('Error al recuperar la orden de servicio.');
            }

            $fresh->withdrawBulls(
                $dto->withdrawalDate,
                $dto->observations !== null && trim($dto->observations) !== '' ? "Retiro: {$dto->observations}" : null
            );

            $saved = $this->serviceOrderRepository->save(
                $fresh,
                $dto->userId,
                "Retiro total de torada ({$bulls} reproductores). La orden sigue abierta hasta completar el diagnóstico de gestación."
            );

            return ['order' => $saved, 'reopened_female_ids' => $reopened];
        });
    }
}
