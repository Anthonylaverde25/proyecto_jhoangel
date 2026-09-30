<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Application\DTOs\WeaningOrders\EmitWeaningOrderDTO;
use App\Application\Services\WeaningOrderRosterBuilder;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderRepository;

/**
 * "Guardar cambios" on a draft: replaces its header, destinations and roll with what the screen
 * shows now.
 */
final class UpdateWeaningOrderDraftUseCase
{
    public function __construct(
        private readonly IWeaningOrderRepository $repository,
        private readonly WeaningOrderRosterBuilder $roster
    ) {
    }

    /**
     * @throws WeaningOrderDomainException
     */
    public function __invoke(int $id, EmitWeaningOrderDTO $dto): WeaningOrderEntity
    {
        $order = $this->repository->findById($id, $dto->companyId) ?? throw WeaningOrderDomainException::notFound();

        [$destinations, $animals] = $this->roster->build($dto, $order->getDestinationActivityId());

        $order->updateDraft(
            $dto->destinationMode,
            $dto->categoryMode,
            $dto->weaningType,
            $dto->weaningDate,
            $dto->responsable,
            $dto->observations,
            $destinations,
            $animals
        );

        return $this->repository->save($order, $dto->requestedByUserId);
    }
}
