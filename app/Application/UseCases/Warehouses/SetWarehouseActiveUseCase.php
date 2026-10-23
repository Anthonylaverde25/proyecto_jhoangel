<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Enums\WarehouseKind;
use App\Core\Exceptions\WarehouseException;
use App\Core\Interfaces\IWarehouseRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates a warehouse. Deactivating is never refused for what it holds: the screen
 * shows it and the user decides (DD4). The default is deactivated only together with choosing the
 * next one of its kind, in one transaction (DD3); the last active tank leaves the tanks without a
 * default, and the next tank activated takes it (SW4).
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
                if ($this->warehouses->findDefault($companyId, $warehouse->kind) === null) {
                    $this->warehouses->swapDefault($companyId, $warehouse->id);
                }

                return;
            }

            if ($warehouse->isDefault) {
                if ($newDefaultId === null && $warehouse->kind === WarehouseKind::CRYO_TANK && !$this->hasOtherActiveTank($warehouse->id, $companyId)) {
                    $this->warehouses->save($warehouse->notDefault()->deactivated());

                    return;
                }
                if ($newDefaultId === null) {
                    throw WarehouseException::on('new_default_id', 'Elegí qué almacén pasa a ser el predeterminado.');
                }
                if ($newDefaultId === $warehouse->id) {
                    throw WarehouseException::on('new_default_id', 'El nuevo predeterminado tiene que ser otro almacén.');
                }
                $next = $this->warehouses->findById($newDefaultId, $companyId)
                    ?? throw WarehouseException::on('new_default_id', 'El almacén elegido no existe en esta empresa.');
                if ($next->kind !== $warehouse->kind) {
                    throw WarehouseException::on('new_default_id', $warehouse->kind === WarehouseKind::CRYO_TANK
                        ? 'El nuevo predeterminado tiene que ser otro termo.'
                        : 'El nuevo predeterminado tiene que ser un almacén general, no un termo.');
                }
                $next->asDefault();
                $this->warehouses->swapDefault($companyId, $next->id);
                $warehouse = $warehouse->notDefault();
            }

            $this->warehouses->save($warehouse->deactivated());
        });

        return ($this->list)($companyId);
    }

    private function hasOtherActiveTank(int $id, int $companyId): bool
    {
        foreach ($this->warehouses->findAll($companyId) as $w) {
            if ($w->id !== $id && $w->isActive && $w->kind === WarehouseKind::CRYO_TANK) {
                return true;
            }
        }

        return false;
    }
}
