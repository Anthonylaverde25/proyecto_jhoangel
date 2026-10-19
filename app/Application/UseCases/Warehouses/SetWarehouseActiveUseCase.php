<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Exceptions\WarehouseException;
use App\Core\Interfaces\IWarehouseRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates a warehouse. Deactivating is never refused for what it holds: the screen
 * shows it and the user decides (DD4). The default is deactivated only together with choosing the
 * next one, in one transaction (DD3).
 */
final class SetWarehouseActiveUseCase
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses,
        private readonly ListWarehousesUseCase $list,
    ) {
    }

    public function __invoke(int $id, bool $active, ?int $newDefaultId, int $companyId): WarehouseListing
    {
        $warehouse = $this->warehouses->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Warehouse {$id} not found.");

        DB::transaction(function () use ($warehouse, $active, $newDefaultId, $companyId) {
            if ($active) {
                $this->warehouses->save($warehouse->activated());

                return;
            }

            if ($warehouse->isDefault) {
                if ($newDefaultId === null) {
                    throw WarehouseException::on('new_default_id', 'Elegí qué almacén pasa a ser el predeterminado.');
                }
                if ($newDefaultId === $warehouse->id) {
                    throw WarehouseException::on('new_default_id', 'El nuevo predeterminado tiene que ser otro almacén.');
                }
                $next = $this->warehouses->findById($newDefaultId, $companyId)
                    ?? throw WarehouseException::on('new_default_id', 'El almacén elegido no existe en esta empresa.');
                $next->asDefault();
                $this->warehouses->swapDefault($companyId, $next->id);
                $warehouse = $warehouse->notDefault();
            }

            $this->warehouses->save($warehouse->deactivated());
        });

        return ($this->list)($companyId);
    }
}
