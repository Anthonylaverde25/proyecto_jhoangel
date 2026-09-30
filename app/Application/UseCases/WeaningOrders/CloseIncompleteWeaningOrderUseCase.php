<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Core\Entities\WeaningOrderEntity;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderRepository;

/**
 * Done, knowing some calves were not weaned with it: the pending ones are recorded as skipped and
 * stop being committed.
 */
final class CloseIncompleteWeaningOrderUseCase
{
    public function __construct(private readonly IWeaningOrderRepository $repository)
    {
    }

    /**
     * @throws WeaningOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, string $reason): WeaningOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw WeaningOrderDomainException::notFound();
        $pending = $order->pendingCount();

        $order->closeIncomplete($reason);

        return $this->repository->save($order, $userId, trim($reason), ['skipped' => $pending]);
    }
}
