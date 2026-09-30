<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Core\Entities\BirthOrderEntity;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Interfaces\IBirthOrderRepository;

/**
 * The season is over, knowing some females did not calve with the order: the pending ones are
 * recorded as skipped and stop being held. Their gestations stay open.
 */
final class CloseIncompleteBirthOrderUseCase
{
    public function __construct(private readonly IBirthOrderRepository $repository)
    {
    }

    /**
     * @throws BirthOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, string $reason): BirthOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw BirthOrderDomainException::notFound();
        $pending = $order->pendingCount();

        $order->closeIncomplete($reason);

        return $this->repository->save($order, $userId, trim($reason), ['skipped' => $pending]);
    }
}
