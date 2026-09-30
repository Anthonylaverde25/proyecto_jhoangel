<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Application\Services\WeaningOrderRosterBuilder;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderRepository;

/**
 * "Emitir orden": draft → issued. The draft may be days old, so what it names is checked again —
 * the calves are still at foot and no other open order took them meanwhile.
 */
final class IssueWeaningOrderUseCase
{
    public function __construct(
        private readonly IWeaningOrderRepository $repository,
        private readonly WeaningOrderRosterBuilder $roster
    ) {
    }

    /**
     * @throws WeaningOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId): WeaningOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw WeaningOrderDomainException::notFound();
        $caravanIds = array_map(fn ($line) => $line->getCaravanId(), $order->getAnimals());

        $this->roster->assertNursingCalves($caravanIds, $companyId);
        $this->roster->assertNotCommitted($caravanIds, $companyId);

        $order->issue();

        return $this->repository->save($order, $userId, 'Borrador emitido');
    }
}
