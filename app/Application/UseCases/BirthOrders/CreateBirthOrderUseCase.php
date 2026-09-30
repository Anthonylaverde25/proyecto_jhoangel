<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Application\DTOs\BirthOrders\EmitBirthOrderDTO;
use App\Application\Services\BirthOrderFactory;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\BirthOrderDomainException;

/**
 * "Nueva orden de parición": a draft by default ("Guardar borrador"), or already issued ("Crear y
 * emitir"). The code is assigned either way, so a draft can be found in the list.
 *
 * An order may take pregnant females of several batches. What cannot happen is one female in two
 * open birth orders.
 */
final class CreateBirthOrderUseCase
{
    public function __construct(private readonly BirthOrderFactory $factory)
    {
    }

    /**
     * @throws BirthOrderDomainException
     */
    public function __invoke(EmitBirthOrderDTO $dto): BirthOrderEntity
    {
        return $this->factory->create(
            $dto,
            TransferOrderKind::PLANNED,
            $dto->issue ? 'Orden de parición emitida' : 'Borrador de orden de parición guardado'
        );
    }
}
