<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;

/**
 * No more DTEs will arrive: fewer head entered than were bought (a death on the road, a rejected
 * animal). The reason is mandatory.
 */
final class CloseIncompleteEntryOrderUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, string $reason): EntryOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();

        $order->closeIncomplete($reason);

        return $this->repository->save($order, $userId, $order->getClosingReason());
    }
}
