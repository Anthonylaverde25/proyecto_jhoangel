<?php

declare(strict_types=1);

namespace App\Application\Services\Insemination;

use App\Application\DTOs\Stock\StockLineDTO;
use App\Application\DTOs\Stock\StockOperationDTO;
use App\Application\Services\Stock\LedgerResult;
use App\Application\UseCases\Stock\RegisterConsumptionUseCase;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Entities\ServiceOrderInseminationEntity;
use App\Core\Exceptions\InseminationException;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IServiceOrderInseminationRepository;

/**
 * Takes out of one tank the straws of a set of inseminations (semen S-B, IA7): one consumption
 * charged to the order's batch, with the heads inseminated and a line per semen and lot, through
 * the regular consumption so every stock rule applies (DB8). Each insemination keeps its line.
 * Must run inside the caller's transaction.
 */
final class InseminationConsumption
{
    public function __construct(
        private readonly RegisterConsumptionUseCase $registerConsumption,
        private readonly IItemRepository $items,
        private readonly IServiceOrderInseminationRepository $inseminations,
    ) {
    }

    /**
     * @param array<int, ServiceOrderInseminationEntity> $byRow inseminations of ONE tank, keyed by their sheet row
     *
     * @throws InseminationException a stock rule broken, under the "rows.{i}.field" of the rows involved
     */
    public function issue(ServiceOrderEntity $order, int $warehouseId, array $byRow, string $idempotencyKey, ?int $userId, string $notes): LedgerResult
    {
        $groups = [];
        foreach ($byRow as $row => $insemination) {
            $key = $insemination->getItemId() . '|' . ($insemination->getSemenLotId() ?? '');
            $groups[$key] ??= ['item' => $insemination->getItemId(), 'lot' => $insemination->getSemenLotId(), 'straws' => 0, 'rows' => []];
            $groups[$key]['straws'] += $insemination->getStrawsUsed();
            $groups[$key]['rows'][] = $row;
        }
        $groups = array_values($groups);

        $lines = [];
        foreach ($groups as $group) {
            $unitId = $this->items->findById($group['item'], $order->getCompanyId())?->getUnitOfMeasureId();
            $lines[] = new StockLineDTO($group['item'], (string) $group['straws'], $unitId, null, null, $group['lot']);
        }

        $heads = count(array_unique(array_map(fn (ServiceOrderInseminationEntity $i) => $i->getFemaleCaravanId(), $byRow)));
        $occurredAt = max(array_map(fn (ServiceOrderInseminationEntity $i) => $i->getInseminatedOn(), $byRow));

        try {
            $result = ($this->registerConsumption)(new StockOperationDTO(
                $occurredAt,
                $idempotencyKey,
                $lines,
                batchId: $order->getBatchId(),
                headsTreated: $heads,
                notes: $notes,
                createdBy: $userId,
                warehouseId: $warehouseId,
                serviceOrderId: $order->getId(),
            ), $order->getCompanyId());
        } catch (ItemDocumentException $e) {
            throw InseminationException::many(self::toRows($e->getErrors(), $groups));
        }

        if (!$result->replayed) {
            $this->attach($result, $byRow);
        }

        return $result;
    }

    /** @param array<int, ServiceOrderInseminationEntity> $byRow */
    private function attach(LedgerResult $result, array $byRow): void
    {
        $lineByKey = [];
        foreach ($result->document->getLines() as $line) {
            $lineByKey[$line->getItemId() . '|' . ($line->getSemenLotId() ?? '')] = (int) $line->getId();
        }
        foreach ($byRow as $insemination) {
            $lineId = $lineByKey[$insemination->getItemId() . '|' . ($insemination->getSemenLotId() ?? '')] ?? null;
            if ($lineId !== null) {
                $insemination->attachLine($lineId);
                $this->inseminations->save($insemination);
            }
        }
    }

    /**
     * "lines.{n}.semen_lot_id" of the consumption becomes the same field on every sheet row of that line.
     *
     * @param array<string, list<string>> $errors
     * @param list<array{rows: list<int>}> $groups
     * @return array<string, list<string>>
     */
    private static function toRows(array $errors, array $groups): array
    {
        $out = [];
        foreach ($errors as $field => $messages) {
            if (preg_match('/^lines\.(\d+)\.(.+)$/', $field, $m) === 1 && isset($groups[(int) $m[1]])) {
                $cell = $m[2] === 'input_quantity' ? 'straws_used' : $m[2];
                foreach ($groups[(int) $m[1]]['rows'] as $row) {
                    $out["rows.{$row}.{$cell}"] = $messages;
                }
                continue;
            }
            // The tank and the date are per row on the sheet: the whole tank's rows carry them.
            $cell = ['warehouse_id' => 'warehouse_id', 'occurred_at' => 'inseminated_on'][$field] ?? null;
            if ($cell !== null) {
                foreach ($groups as $group) {
                    foreach ($group['rows'] as $row) {
                        $out["rows.{$row}.{$cell}"] = $messages;
                    }
                }
                continue;
            }
            $out['domain'] = array_merge($out['domain'] ?? [], $messages);
        }

        return $out;
    }
}
