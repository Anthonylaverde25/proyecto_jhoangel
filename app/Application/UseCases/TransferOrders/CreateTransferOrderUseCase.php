<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Application\DTOs\TransferOrders\EmitTransferOrderDTO;
use App\Application\Services\TransferOrderFactory;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ITransferOrderRepository;

/**
 * Creates a transfer order: a draft by default ("Guardar borrador"), or already issued ("Crear y
 * emitir orden"). The code is assigned either way, so a draft can be found in the list.
 *
 * A source batch holds at most one active order at a time, drafts included.
 */
final class CreateTransferOrderUseCase
{
    public function __construct(
        private readonly ITransferOrderRepository $repository,
        private readonly TransferOrderFactory $factory
    ) {
    }

    /**
     * @throws TransferOrderDomainException
     */
    public function __invoke(EmitTransferOrderDTO $dto): TransferOrderEntity
    {
        $this->assertSourceBatchIsFree($dto);

        return $this->factory->create(
            $dto,
            TransferOrderKind::PLANNED,
            $dto->issue ? 'Orden emitida desde la pantalla de transferencia' : 'Borrador guardado desde la pantalla de transferencia'
        );
    }

    /**
     * One active order (draft, issued or partial) per source batch: a second one is refused
     * until the first is executed, closed or cancelled.
     *
     * @throws TransferOrderDomainException
     */
    private function assertSourceBatchIsFree(EmitTransferOrderDTO $dto): void
    {
        foreach ($this->repository->list($dto->companyId, null, $dto->sourceBatchId) as $order) {
            if ($order->getStatus()->isActive()) {
                throw TransferOrderDomainException::sourceBatchHasActiveOrder(
                    $order->getCode(),
                    $order->getStatus()->label()
                );
            }
        }
    }
}
