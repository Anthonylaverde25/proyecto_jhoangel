<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Core\Entities\WeaningOrderEntity;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderRepository;

/**
 * Cancels an order nothing was executed against: discarding a draft (reason optional) or cancelling
 * an issued order (reason required).
 */
final class CancelWeaningOrderUseCase
{
    public function __construct(private readonly IWeaningOrderRepository $repository)
    {
    }

    /**
     * @throws WeaningOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, ?string $reason): WeaningOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw WeaningOrderDomainException::notFound();

        $order->cancel($reason);

        return $this->repository->save($order, $userId, $order->getClosingReason());
    }
}
