<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Interfaces\ITransferOrderRepository;
use App\Core\Interfaces\IWeaningOrderRepository;

/**
 * Which animals an open order already commits, whatever kind of order it is.
 *
 * Transfer orders and weaning orders live in different tables, but an animal is one animal: a
 * calf pending in an issued weaning order cannot be taken by a transfer order, nor by a second
 * weaning order, and the other way round. Every issue and every registration asks here.
 */
final class OpenOrderCommitmentChecker
{
    public function __construct(
        private readonly ITransferOrderRepository $transferOrders,
        private readonly IWeaningOrderRepository $weaningOrders
    ) {
    }

    /**
     * @param int[] $caravanIds
     * @return array<int, string> caravan id => code of the open order that holds it
     */
    public function committed(array $caravanIds, int $companyId): array
    {
        if ($caravanIds === []) {
            return [];
        }

        return $this->transferOrders->findCommittedCaravans($caravanIds, $companyId)
            + $this->weaningOrders->findCommittedCaravans($caravanIds, $companyId);
    }

    /**
     * The sentence both kinds of order refuse with, naming the orders that hold the animals.
     *
     * @param array<int, string> $committed
     */
    public static function message(array $committed, string $noun = 'animal(es)'): string
    {
        $codes = implode(', ', array_unique(array_values($committed)));

        return count($committed) . " {$noun} ya están comprometidos en la orden {$codes}. Ejecutala, cerrala o anulala primero.";
    }
}
