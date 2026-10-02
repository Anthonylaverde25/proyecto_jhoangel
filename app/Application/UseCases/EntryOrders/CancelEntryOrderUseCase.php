<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use App\Models\Batch;
use App\Models\Caravan;
use Illuminate\Support\Facades\DB;

/**
 * The purchase did not happen: a draft is discarded (reason optional), a confirmed order without
 * DTEs is cancelled (reason required). The external batch it created never had animals, so it is
 * deactivated rather than left in the list as an empty batch nobody expects anything from.
 */
final class CancelEntryOrderUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, ?string $reason): EntryOrderEntity
    {
        return DB::transaction(function () use ($id, $companyId, $userId, $reason): EntryOrderEntity {
            $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();

            $order->cancel($reason);

            $batchId = $order->getBatchId();
            if ($batchId !== null && !Caravan::withoutGlobalScopes()->where('batch_id', $batchId)->exists()) {
                Batch::withoutGlobalScopes()->whereKey($batchId)->update(['is_active' => false]);
            }

            return $this->repository->save($order, $userId, $order->getClosingReason());
        });
    }
}
