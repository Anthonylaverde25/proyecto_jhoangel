<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Application\DTOs\EntryOrders\WriteOffPendingHeadsDTO;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Dar de baja pendientes": head received without caravan that died or were lost before the chute
 * wrote their caravan. Their batch stops counting them, an incident keeps the reason, and an order
 * with nothing left to identify closes.
 */
final class WriteOffPendingHeadsUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, WriteOffPendingHeadsDTO $dto): EntryOrderEntity
    {
        return DB::transaction(function () use ($id, $companyId, $userId, $dto): EntryOrderEntity {
            $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();

            $order->writeOffPending($dto->dteId, $dto->batchId, $dto->headCount, $dto->reason, $userId);

            return $this->repository->save($order, $userId, $dto->reason, [
                'action' => 'pending_heads_written_off',
                'dte_id' => $dto->dteId,
                'batch_id' => $dto->batchId,
                'head_count' => $dto->headCount,
            ]);
        });
    }
}
