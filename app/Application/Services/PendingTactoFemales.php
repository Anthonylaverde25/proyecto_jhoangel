<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderRepository;

/**
 * Females still waiting for their tacto in an open order, wherever they are today: a cow moved to
 * another batch still owes that diagnosis. Read from each order's own summary, so "pending" means
 * here what it means when closing the order.
 */
final class PendingTactoFemales
{
    private const NAMED = 5;

    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly ICaravanRepository $caravans
    ) {
    }

    /**
     * @param int[]|null $femaleIds null: every female of the company
     * @param int|null $exceptOrderId the order asking, whose own tacto does not count
     * @return array<int, array{order_id: int, order_code: string}> by female
     */
    public function of(?array $femaleIds, int $companyId, ?int $exceptOrderId = null): array
    {
        if ($femaleIds === []) {
            return [];
        }

        $pending = [];
        foreach ($this->orders->findOpenOrderIdsHoldingFemales($femaleIds, $companyId) as $orderId) {
            if ($orderId === $exceptOrderId) {
                continue;
            }

            $order = $this->orders->findById($orderId, $companyId);
            if ($order === null) {
                continue;
            }

            foreach ($order->getDiagnosisSummary()->getPendingFemaleIds() as $femaleId) {
                if ($femaleIds === null || in_array($femaleId, $femaleIds, true)) {
                    $pending[$femaleId] ??= ['order_id' => $orderId, 'order_code' => $order->getCode()];
                }
            }
        }

        return $pending;
    }

    /**
     * "SRV-ET-05 (SO-2026-903), SRV-ET-07 (SO-2026-903) y 2 más".
     *
     * @param array<int, array{order_id: int, order_code: string}> $pending
     */
    public function describe(array $pending): string
    {
        $named = [];
        foreach (array_slice($pending, 0, self::NAMED, true) as $femaleId => $order) {
            $tag = $this->caravans->findById($femaleId)?->getIdentification()->getValue() ?? "#{$femaleId}";
            $named[] = "{$tag} ({$order['order_code']})";
        }
        $others = count($pending) - count($named);

        return implode(', ', $named) . ($others > 0 ? " y {$others} más" : '');
    }
}
