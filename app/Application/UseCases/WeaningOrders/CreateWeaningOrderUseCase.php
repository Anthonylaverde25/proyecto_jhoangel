<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Application\DTOs\WeaningOrders\EmitWeaningOrderDTO;
use App\Application\Services\WeaningOrderFactory;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\WeaningOrderDomainException;

/**
 * "Nueva orden de destete": a draft by default ("Guardar borrador"), or already issued ("Crear y
 * emitir"). The code is assigned either way, so a draft can be found in the list.
 *
 * Unlike a transfer order there is no "one active order per batch": a weaning order may take calves
 * of several breeding batches. What cannot happen is one calf in two open orders.
 */
final class CreateWeaningOrderUseCase
{
    public function __construct(private readonly WeaningOrderFactory $factory)
    {
    }

    /**
     * @throws WeaningOrderDomainException
     */
    public function __invoke(EmitWeaningOrderDTO $dto): WeaningOrderEntity
    {
        return $this->factory->create(
            $dto,
            TransferOrderKind::PLANNED,
            $dto->issue ? 'Orden de destete emitida' : 'Borrador de orden de destete guardado'
        );
    }
}
