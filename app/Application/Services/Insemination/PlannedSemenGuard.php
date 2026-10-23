<?php

declare(strict_types=1);

namespace App\Application\Services\Insemination;

use App\Application\Services\Stock\SemenStorageRule;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IItemRepository;

/**
 * The semen planned for the females of an AI order (semen S-B, IA4): optional, but when given it is
 * an active semen item of the company and the female is one of the order.
 */
final class PlannedSemenGuard
{
    public function __construct(
        private readonly IItemRepository $items,
        private readonly SemenStorageRule $semen,
    ) {
    }

    /**
     * @param array<int, int> $plannedSemen female_caravan_id => item id
     * @param int[] $femaleIds females of the order
     * @return array<int, int> the planned semen restricted to the order's females
     *
     * @throws ServiceOrderDomainException
     */
    public function check(array $plannedSemen, array $femaleIds, int $companyId): array
    {
        $checked = [];
        foreach (array_unique(array_values($plannedSemen)) as $itemId) {
            $item = $this->items->findById((int) $itemId, $companyId);
            if ($item === null || !$this->semen->isSemen($item)) {
                throw ServiceOrderDomainException::domainError("El semen previsto (ID {$itemId}) no es un semen de la empresa.");
            }
            if (!$item->isActive()) {
                throw ServiceOrderDomainException::domainError("El semen previsto {$item->getName()} está inactivo.");
            }
            $checked[(int) $itemId] = true;
        }

        $outside = array_diff(array_keys($plannedSemen), $femaleIds);
        if ($outside !== []) {
            throw ServiceOrderDomainException::domainError(
                'Hay semen previsto para vientres que no están en la orden (IDs: ' . implode(', ', $outside) . ').'
            );
        }

        return $plannedSemen;
    }
}
