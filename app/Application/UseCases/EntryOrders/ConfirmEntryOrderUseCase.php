<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Application\Services\EntryOrderFactory;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * Draft → awaiting DTE: the purchase is closed. Creates the external batch, empty.
 */
final class ConfirmEntryOrderUseCase
{
    public function __construct(
        private readonly EntryOrderFactory $factory,
        private readonly IEntryOrderRepository $repository
    ) {
    }

    /**
     * @return array{order: EntryOrderEntity, warnings: list<array{code: string, message: string}>}
     *
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId): array
    {
        return DB::transaction(function () use ($id, $companyId, $userId): array {
            $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
            $warnings = $this->factory->confirm($order);

            return [
                'order' => $this->repository->save($order, $userId, 'Compra confirmada: en espera de DTE'),
                'warnings' => $warnings,
            ];
        });
    }
}
