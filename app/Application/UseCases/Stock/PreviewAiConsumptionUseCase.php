<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Application\DTOs\Stock\AiConsumptionDTO;
use App\Application\Services\Stock\AiConsumptionEvaluation;
use App\Application\Services\Stock\AiConsumptionPolicy;
use App\Application\Services\Stock\AiConsumptionToken;
use App\Application\Services\Stock\ResolvedLine;
use App\Application\Services\Stock\StockIncidentRaiser;
use App\Core\Enums\WarehouseKind;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\Services\ConsumptionSourcePlanner;
use App\Core\ValueObjects\ConsumptionSourceOption;
use App\Core\ValueObjects\Decimal;
use App\Core\ValueObjects\WarehouseBalance;

/**
 * What would happen if this consumption were recorded, computed by the system because the model must
 * not: the quantity in the item's unit, the balance per warehouse, the options to take it from, and the
 * estimated cost. Writes nothing. A business blocker is an answer, not an error.
 */
final class PreviewAiConsumptionUseCase
{
    public function __construct(
        private readonly AiConsumptionPolicy $policy,
        private readonly IItemStockRepository $stocks,
        private readonly IWarehouseRepository $warehouses,
        private readonly ConsumptionSourcePlanner $planner,
        private readonly AiConsumptionToken $tokens,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(AiConsumptionDTO $dto, int $companyId): array
    {
        $evaluation = $this->policy->evaluate($dto, $companyId);
        $line = $evaluation->line;
        if ($evaluation->blockers !== [] || $line === null || $evaluation->batch === null || $evaluation->warehouse === null) {
            return ['can_register' => false, 'blockers' => $evaluation->blockers, 'warnings' => [], 'options' => []];
        }

        $balances = $this->balances((int) $line->item->getId(), $evaluation->warehouse->id, $companyId);
        $options = $this->planner->plan($balances, $evaluation->warehouse->id, $line->quantity);

        return [
            'can_register' => true,
            'blockers' => [],
            'warnings' => $evaluation->warnings,
            'item' => ['id' => $line->item->getId(), 'name' => $line->item->getName(), 'unit_symbol' => $line->item->getUnit()?->getSymbol()],
            'input_label' => $this->inputLabel($line),
            'input_quantity' => $line->inputQuantity->toScale(4),
            'input_unit_id' => $line->inputUnitId,
            'input_presentation_id' => $line->inputPresentationId,
            'quantity' => $line->quantity->toScale(4),
            'batch' => ['id' => $evaluation->batch->id, 'name' => $evaluation->batch->name, 'head_count' => $evaluation->batch->headCount],
            'heads_treated' => $evaluation->headsTreated,
            'heads_source' => $dto->headsTreated === null ? 'BATCH_CURRENT' : 'DECLARED',
            'occurred_at' => $dto->occurredAt,
            'requested_warehouse' => ['id' => $evaluation->warehouse->id, 'code' => $evaluation->warehouse->code, 'name' => $evaluation->warehouse->name],
            'stock_by_warehouse' => array_map(fn (WarehouseBalance $b) => [
                'warehouse_id' => $b->warehouseId,
                'code' => $b->code,
                'name' => $b->name,
                'quantity' => $b->balance->toScale(4),
                'unvalued_quantity' => $b->unvalued->toScale(4),
                'transferable_quantity' => $b->transferable()->toScale(4),
            ], $balances),
            'needs_choice' => count($options) > 1,
            'options' => array_map(fn (ConsumptionSourceOption $o) => $this->optionShape($o, $dto, $evaluation, $companyId), $options),
        ];
    }

    /**
     * The active general warehouses (a tank never holds these articles), plus the one asked for.
     *
     * @return list<WarehouseBalance>
     */
    private function balances(int $itemId, int $warehouseId, int $companyId): array
    {
        $stocks = [];
        foreach ($this->stocks->findByItem($itemId, $companyId) as $stock) {
            $stocks[$stock->getWarehouseId()] = $stock;
        }

        $balances = [];
        foreach ($this->warehouses->findAll($companyId) as $warehouse) {
            if ($warehouse->id !== $warehouseId && (!$warehouse->isActive || $warehouse->kind !== WarehouseKind::GENERAL)) {
                continue;
            }
            $stock = $stocks[$warehouse->id] ?? null;
            $critical = $stock?->getCriticalStock();
            $balances[] = new WarehouseBalance(
                $warehouse->id,
                $warehouse->code,
                $warehouse->name,
                Decimal::of($stock?->getQuantity() ?? '0'),
                Decimal::of($stock?->getUnvaluedQuantity() ?? '0'),
                Decimal::of($stock?->getAverageUnitCost() ?? '0'),
                $critical === null ? null : Decimal::of($critical),
            );
        }

        return $balances;
    }

    /** @return array<string, mixed> */
    private function optionShape(ConsumptionSourceOption $option, AiConsumptionDTO $dto, AiConsumptionEvaluation $evaluation, int $companyId): array
    {
        $heads = (int) $evaluation->headsTreated;
        $transfers = array_map(fn (array $t) => ['from_warehouse_id' => $t['from']->warehouseId, 'quantity' => $t['quantity']->toScale(4)], $option->transfers);
        $perHead = $option->costKnown && $heads > 0 ? $option->estimatedTotal->div(Decimal::of($heads))->round(4)->toScale(4) : null;

        return [
            'code' => $option->code,
            'warehouse' => ['id' => $option->warehouse->warehouseId, 'code' => $option->warehouse->code, 'name' => $option->warehouse->name],
            'transfers' => array_map(fn (array $t) => [
                'from_warehouse_id' => $t['from']->warehouseId,
                'from_code' => $t['from']->code,
                'from_name' => $t['from']->name,
                'quantity' => $t['quantity']->toScale(4),
                'balance_after' => $t['from']->balance->sub($t['quantity'])->toScale(4),
            ], $option->transfers),
            'balance_after' => $option->balanceAfter->toScale(4),
            'goes_negative' => $option->goesNegative(),
            'falls_below_critical' => $option->fallsBelowCritical(),
            'critical_stock' => $option->warehouse->critical?->toScale(4),
            'cost' => [
                'known' => $option->costKnown,
                'estimated_total' => $option->costKnown ? $option->estimatedTotal->toScale(4) : null,
                'estimated_per_head' => $perHead,
                'pending_quantity' => $option->pendingQuantity->toScale(4),
            ],
            'confirmation_token' => $this->tokens->issue($dto->facts($companyId, $option->warehouse->warehouseId, $heads, $transfers)),
        ];
    }

    private function inputLabel(ResolvedLine $line): string
    {
        $quantity = StockIncidentRaiser::quantity($line->inputQuantity->toScale(4));
        if ($line->inputPresentationId !== null) {
            foreach ($line->item->getPresentations() as $presentation) {
                if ($presentation->getId() === $line->inputPresentationId) {
                    return "{$quantity} {$presentation->getName()}";
                }
            }
        }

        return trim("{$quantity} " . $line->item->getUnit()?->getSymbol());
    }
}
