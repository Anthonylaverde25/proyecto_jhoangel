<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Core\Entities\BirthOrderEntity;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Interfaces\IBirthOrderRepository;

/**
 * Cancels an order nothing was executed against: discarding a draft (reason optional) or cancelling
 * an issued order (reason required).
 */
final class CancelBirthOrderUseCase
{
    public function __construct(private readonly IBirthOrderRepository $repository)
    {
    }

    /**
     * @throws BirthOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, ?string $reason): BirthOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw BirthOrderDomainException::notFound();

        $order->cancel($reason);

        return $this->repository->save($order, $userId, $order->getClosingReason());
    }
}
