<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Application\Services\Stock\StockIncidentRaiser;
use App\Core\Entities\ItemEntity;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IItemUsageReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * An inactive item stays in the catalog but is not offered for new documents. An item with a balance
 * in some warehouse is not deactivated (I10).
 */
final class SetItemActiveUseCase
{
    public function __construct(
        private readonly IItemRepository $repository,
        private readonly IItemUsageReader $usage,
    ) {
    }

    public function __invoke(int $id, bool $active, int $companyId): ItemEntity
    {
        $item = $this->repository->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Item {$id} not found.");

        if (!$active && $item->isActive()) {
            $balance = $this->usage->nonZeroBalance($id, $companyId);
            if ($balance !== null) {
                throw ItemCatalogException::itemWithBalance(
                    $item->getName(),
                    trim(StockIncidentRaiser::quantity($balance) . ' ' . $item->getUnit()?->getSymbol()),
                );
            }
        }
        $item->setActive($active);

        return $this->repository->save($item);
    }
}
