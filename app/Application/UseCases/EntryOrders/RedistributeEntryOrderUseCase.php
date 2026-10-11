<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Application\DTOs\EntryOrders\RedistributeEntryOrderDTO;
use App\Application\Services\EntryOrderDestinationValidator;
use App\Application\Services\EntryOrderFactory;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Editar distribución": changes where the animals of an order go from now on, while it still
 * receives animals. The ones already received stay where they are (a transfer moves them). When
 * something is left without a destination and the order has no transit batch, it is created.
 */
final class RedistributeEntryOrderUseCase
{
    public function __construct(
        private readonly EntryOrderFactory $factory,
        private readonly EntryOrderDestinationValidator $destinationValidator,
        private readonly IEntryOrderRepository $repository
    ) {
    }

    /**
     * @return array{order: EntryOrderEntity, warnings: list<array{code: string, message: string}>}
     *
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, RedistributeEntryOrderDTO $dto): array
    {
        return DB::transaction(function () use ($id, $companyId, $userId, $dto): array {
            $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();

            $this->destinationValidator->assertUsable($dto->destinations->batchIds());
            $order->redistribute($dto->destinations, $dto->transitBatchName);
            $warnings = $this->factory->attachTransitBatchIfNeeded($order);

            $saved = $this->repository->save($order, $userId, 'Distribución de la hacienda actualizada', [
                'action' => 'destinations_changed',
                'destinations' => count($dto->destinations->rules()),
                'transit_batch_id' => $order->getBatchId(),
            ]);

            return ['order' => $saved, 'warnings' => $warnings];
        });
    }
}
