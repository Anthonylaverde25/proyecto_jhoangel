<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Application\DTOs\BirthOrders\EmitBirthOrderDTO;
use App\Application\Services\BirthOrderRosterBuilder;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Interfaces\IBirthOrderRepository;

/**
 * "Guardar cambios" on a draft: replaces its header and roll with what the screen shows now.
 */
final class UpdateBirthOrderDraftUseCase
{
    public function __construct(
        private readonly IBirthOrderRepository $repository,
        private readonly BirthOrderRosterBuilder $roster
    ) {
    }

    /**
     * @throws BirthOrderDomainException
     */
    public function __invoke(int $id, EmitBirthOrderDTO $dto): BirthOrderEntity
    {
        $order = $this->repository->findById($id, $dto->companyId) ?? throw BirthOrderDomainException::notFound();

        $order->updateDraft(
            $dto->periodStart,
            $dto->periodEnd,
            $dto->responsable,
            $dto->observations,
            $this->roster->build($dto)
        );

        return $this->repository->save($order, $dto->requestedByUserId);
    }
}
