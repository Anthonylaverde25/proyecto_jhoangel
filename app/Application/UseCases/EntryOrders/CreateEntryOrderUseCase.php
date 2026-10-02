<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Application\DTOs\EntryOrders\StoreEntryOrderDTO;
use App\Application\Services\EntryOrderFactory;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Nueva orden de ingreso": a draft ("Guardar borrador") or a confirmed purchase ("Confirmar
 * compra"), which creates the external batch empty and leaves the order waiting for its DTE.
 */
final class CreateEntryOrderUseCase
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
    public function __invoke(StoreEntryOrderDTO $dto): array
    {
        return DB::transaction(function () use ($dto): array {
            $built = $this->factory->build($dto, TransferOrderKind::PLANNED, $dto->confirm);

            $order = $this->repository->save(
                $built['order'],
                $dto->userId,
                $dto->confirm ? 'Compra confirmada: en espera de DTE' : 'Borrador de orden de ingreso guardado'
            );

            return ['order' => $order, 'warnings' => $built['warnings']];
        });
    }
}
