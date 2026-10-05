<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Application\DTOs\EntryOrders\ReceiveDTO;
use App\Application\Services\EntryOrderReceptionService;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Recibir": the animals of a DTE (by hand) or read at the chute arrived. All or nothing.
 */
final class ReceiveEntryOrderUseCase
{
    public function __construct(
        private readonly EntryOrderReceptionService $receptionService,
        private readonly IEntryOrderRepository $repository
    ) {
    }

    /**
     * @return array{order: EntryOrderEntity, warnings: list<array{code: string, message: string, row?: int}>}
     *
     * @throws EntryOrderDomainException
     * @throws EntryDteValidationException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, ReceiveDTO $dto): array
    {
        return DB::transaction(function () use ($id, $companyId, $userId, $dto): array {
            $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
            $result = $this->receptionService->receive($order, $dto, $userId);
            $saved = $this->repository->save($order, $userId, $dto->reason, $result['metadata']);
            $this->receptionService->settleBatch($saved, $result['metadata']);

            return [
                'order' => $saved,
                'warnings' => $result['warnings'],
            ];
        });
    }
}
