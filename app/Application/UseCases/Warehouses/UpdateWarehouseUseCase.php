<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Entities\WarehouseEntity;
use App\Core\Enums\WarehouseKind;
use App\Core\Exceptions\WarehouseException;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\ValueObjects\WarehouseCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Renames a warehouse (DD1) and, while nothing moved through it, changes its kind (SW1). A tank that
 * stops being one hands the tanks' default over only when no other active tank could take it; a
 * warehouse that becomes the first active tank becomes the tanks' default (SW4).
 */
final class UpdateWarehouseUseCase
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses,
        private readonly WarehouseUniqueness $uniqueness,
    ) {
    }

    public function __invoke(int $id, string $code, string $name, ?WarehouseKind $kind, int $companyId): WarehouseEntity
    {
        $warehouse = $this->warehouses->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Warehouse {$id} not found.");
        $renamed = $warehouse->renamed(new WarehouseCode($code), $name);
        $this->uniqueness->check($companyId, $renamed->code, $renamed->name, $id);

        if ($kind === null || $kind === $warehouse->kind) {
            return $this->warehouses->save($renamed);
        }

        $retyped = $renamed->retyped($kind, $this->warehouses->hasMovements($id, $companyId));
        if ($warehouse->isDefault && $this->otherActiveOfKind($warehouse) !== []) {
            throw WarehouseException::on('kind', 'Es el termo predeterminado: elegí antes otro termo predeterminado.');
        }

        return DB::transaction(function () use ($retyped, $companyId) {
            $saved = $this->warehouses->save($retyped);
            if ($saved->isActive && $this->warehouses->findDefault($companyId, $saved->kind) === null) {
                $this->warehouses->swapDefault($companyId, $saved->id);

                return $this->warehouses->findById($saved->id, $companyId) ?? $saved;
            }

            return $saved;
        });
    }

    /** @return list<WarehouseEntity> */
    private function otherActiveOfKind(WarehouseEntity $warehouse): array
    {
        return array_values(array_filter(
            $this->warehouses->findAll($warehouse->companyId),
            fn (WarehouseEntity $w) => $w->id !== $warehouse->id && $w->kind === $warehouse->kind && $w->isActive,
        ));
    }
}
