<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\ItemStockEntity;
use App\Core\Entities\StockIncidentEntity;
use App\Core\Entities\StockMovementEntity;
use App\Core\Enums\StockIncidentType;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IStockIncidentRepository;

/**
 * Opens, refreshes and resolves the non-blocking stock notices after a balance changes (plan 4.8).
 * At most one open notice per warehouse, item and type.
 */
final class StockIncidentRaiser
{
    /** @var array<int, array{name: string, symbol: string}> */
    private array $items = [];

    public function __construct(
        private readonly IStockIncidentRepository $incidents,
        private readonly IItemRepository $itemRepository,
    ) {
    }

    /**
     * Negative balance and critical stock after a movement. $reference names what moved it
     * ("consumo N.º 6"), for the notice's text and for the automatic resolution.
     *
     * @return list<StockIncidentEntity> notices opened or refreshed
     */
    public function afterMovement(ItemStockEntity $stock, StockMovementEntity $movement, string $reference, ?int $userId, string $now): array
    {
        $item = $this->item($stock);
        $balance = self::quantity($stock->getQuantity()) . " {$item['symbol']}";
        $raised = [];

        $negative = bccomp($stock->getQuantity(), '0', 4) < 0;
        $raised[] = $this->toggle(
            $stock,
            StockIncidentType::NEGATIVE_BALANCE,
            $negative,
            $movement->id,
            "{$item['name']} quedó en {$balance} en {$stock->getWarehouseName()} ({$reference}).",
            ['balance' => $stock->getQuantity(), 'unit_symbol' => $item['symbol'], 'reference' => $reference],
            "Resuelta por {$reference}: el saldo volvió a {$balance}.",
            $userId,
            $now,
        );
        $raised[] = $this->critical($stock, $movement->id, $reference, $userId, $now);

        return array_values(array_filter($raised));
    }

    /** Re-evaluates the critical level against the current balance, with no movement (H3). */
    public function afterCriticalLevelChange(ItemStockEntity $stock, ?int $userId, string $now): ?StockIncidentEntity
    {
        return $this->critical($stock, null, 'el cambio del nivel crítico', $userId, $now);
    }

    public function countDiscrepancy(ItemStockEntity $stock, StockMovementEntity $movement, int $countNumber, string $counted, string $system, ?int $userId, string $now): StockIncidentEntity
    {
        $item = $this->item($stock);
        $difference = bcsub($counted, $system, 4);
        $sign = bccomp($difference, '0', 4) > 0 ? '+' : '';
        $detail = "Conteo N.º {$countNumber}: se contaron " . self::quantity($counted) . " {$item['symbol']} y el sistema tenía "
            . self::quantity($system) . " {$item['symbol']} ({$sign}" . self::quantity($difference) . " {$item['symbol']}).";
        $metadata = ['counted' => $counted, 'system' => $system, 'difference' => $difference, 'count_number' => $countNumber, 'unit_symbol' => $item['symbol']];

        $open = $this->incidents->findOpen($stock->getWarehouseId(), $stock->getItemId(), StockIncidentType::COUNT_DISCREPANCY, $stock->getCompanyId());
        if ($open !== null) {
            $open->refresh($movement->id, $detail, $metadata);

            return $this->incidents->save($open);
        }

        return $this->incidents->save(new StockIncidentEntity(
            null, $stock->getCompanyId(), $stock->getWarehouseId(), $stock->getItemId(),
            StockIncidentType::COUNT_DISCREPANCY, $movement->id, $detail, $metadata, raisedBy: $userId,
        ));
    }

    private function critical(ItemStockEntity $stock, ?int $movementId, string $reference, ?int $userId, string $now): ?StockIncidentEntity
    {
        $item = $this->item($stock);
        $level = $stock->getCriticalStock();
        $balance = self::quantity($stock->getQuantity()) . " {$item['symbol']}";

        return $this->toggle(
            $stock,
            StockIncidentType::CRITICAL_STOCK,
            $stock->isBelowCritical(),
            $movementId,
            "{$item['name']} llegó a {$balance}; el nivel crítico es " . self::quantity((string) $level) . " {$item['symbol']}.",
            ['balance' => $stock->getQuantity(), 'critical_stock' => $level, 'unit_symbol' => $item['symbol'], 'reference' => $reference],
            $level === null ? "Resuelta por {$reference}: ya no hay nivel crítico." : "Resuelta por {$reference}: el saldo volvió a {$balance}.",
            $userId,
            $now,
        );
    }

    /**
     * Opens or refreshes the notice while the condition holds; resolves the open one when it stops.
     *
     * @param array<string, mixed> $metadata
     */
    private function toggle(
        ItemStockEntity $stock,
        StockIncidentType $type,
        bool $holds,
        ?int $movementId,
        string $detail,
        array $metadata,
        string $resolution,
        ?int $userId,
        string $now,
    ): ?StockIncidentEntity {
        $open = $this->incidents->findOpen($stock->getWarehouseId(), $stock->getItemId(), $type, $stock->getCompanyId());
        if (!$holds) {
            if ($open !== null) {
                $open->resolve($resolution, $userId, $now);
                $this->incidents->save($open);
            }

            return null;
        }
        if ($open !== null) {
            $open->refresh($movementId, $detail, $metadata);

            return $this->incidents->save($open);
        }

        return $this->incidents->save(new StockIncidentEntity(
            null, $stock->getCompanyId(), $stock->getWarehouseId(), $stock->getItemId(),
            $type, $movementId, $detail, $metadata, raisedBy: $userId,
        ));
    }

    /** @return array{name: string, symbol: string} */
    private function item(ItemStockEntity $stock): array
    {
        if (!isset($this->items[$stock->getItemId()])) {
            $item = $this->itemRepository->findById($stock->getItemId(), $stock->getCompanyId());
            $this->items[$stock->getItemId()] = ['name' => $item?->getName() ?? 'El ítem', 'symbol' => $item?->getUnit()?->getSymbol() ?? ''];
        }

        return $this->items[$stock->getItemId()];
    }

    /** "4500.0000" → "4.500"; "-35.5000" → "−35,5". */
    public static function quantity(string $value): string
    {
        $negative = str_starts_with($value, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $fraction = rtrim($fraction, '0');
        $grouped = strrev(implode('.', str_split(strrev(ltrim($integer, '0') ?: '0'), 3)));

        return ($negative ? '−' : '') . $grouped . ($fraction === '' ? '' : ',' . $fraction);
    }
}
