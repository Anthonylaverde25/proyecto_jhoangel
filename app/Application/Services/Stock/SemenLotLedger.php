<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Entities\SemenUnidentifiedEntryEntity;
use App\Core\Entities\StockIncidentEntity;
use App\Core\Entities\StockMovementEntity;
use App\Core\Enums\ItemDocumentType;
use App\Core\Enums\StockIncidentType;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\ISemenLotRepository;
use App\Core\Interfaces\ISemenUnidentifiedRepository;
use App\Core\Interfaces\IStockIncidentRepository;

/**
 * Keeps the lots of semen in step with the ledger (semen S-A, SL3): called by the StockLedger for every
 * persisted operation, inside its transaction and after it locked the item rows (DA11). A line with a
 * lot moves that lot's balance in the operation's tank and never leaves it below zero (SL4, SL8). A
 * semen line without lot leaves its straws pending with an "Identificar partida" notice (SL6, SU1); its
 * reversal voids them, and takes back from the lots what was already identified (SU8).
 */
final class SemenLotLedger
{
    public function __construct(
        private readonly ISemenLotRepository $lots,
        private readonly ISemenUnidentifiedRepository $unidentified,
        private readonly IStockIncidentRepository $incidents,
        private readonly IItemRepository $items,
        private readonly SemenStorageRule $semen,
    ) {
    }

    /**
     * @param list<array{0: ItemDocumentLineEntity, 1: StockMovementEntity}> $moved lines with their movement
     * @return list<StockIncidentEntity> notices opened
     */
    public function afterApply(ItemDocumentEntity $saved, array $moved, ?int $userId): array
    {
        $raised = [];
        foreach ($moved as [$line, $movement]) {
            if ($movement->direction === 0) {
                continue;
            }
            $quantity = $movement->quantity;
            if ($line->getSemenLotId() !== null) {
                $this->move($saved, $line, $line->getSemenLotId(), $movement->direction > 0 ? $quantity : bcmul($quantity, '-1', 4));
                continue;
            }
            $item = $this->items->findById($line->getItemId(), $saved->getCompanyId());
            if ($item === null || !$this->semen->isSemen($item)) {
                continue;
            }
            if ($line->getReversesLineId() !== null) {
                $this->undoPending($saved, $line->getReversesLineId(), $userId);
                continue;
            }
            $raised[] = $this->pending($saved, $line, $movement, $item->getName(), $userId);
        }

        return $raised;
    }

    /** Applies a signed quantity to the lot in the operation's tank (SL4, SL8). */
    private function move(ItemDocumentEntity $saved, ItemDocumentLineEntity $line, int $lotId, string $delta): void
    {
        $companyId = $saved->getCompanyId();
        $current = $this->lots->lockBalance($lotId, $saved->getWarehouseId(), $companyId);
        $after = bcadd($current, $delta, 4);
        if (bccomp($after, '0', 4) < 0) {
            $code = $this->lots->findById($lotId, $companyId)?->code ?? '';
            $there = SemenUnidentifiedEntryEntity::format($current);
            $what = $saved->getType() === ItemDocumentType::REVERSAL
                ? "No se puede anular: la partida {$code} tiene {$there} pajuelas en este termo y la anulación necesita sacar " . SemenUnidentifiedEntryEntity::format(ltrim($delta, '-')) . '.'
                : "La partida {$code} tiene {$there} pajuelas en este termo: no alcanza para " . SemenUnidentifiedEntryEntity::format(ltrim($delta, '-')) . '.';
            $field = $saved->getType() === ItemDocumentType::REVERSAL ? 'domain' : 'lines.' . ($line->getPosition() - 1) . '.semen_lot_id';
            throw ItemDocumentException::on($field, $what);
        }
        $this->lots->setBalance($lotId, $saved->getWarehouseId(), $companyId, $after);
    }

    private function pending(ItemDocumentEntity $saved, ItemDocumentLineEntity $line, StockMovementEntity $movement, string $itemName, ?int $userId): StockIncidentEntity
    {
        $entry = $this->unidentified->create(new SemenUnidentifiedEntryEntity(
            null, $saved->getCompanyId(), $line->getItemId(), $saved->getWarehouseId(), (int) $line->getId(), null,
            $movement->direction, $movement->quantity,
        ));
        $incident = $this->refreshNotice($saved->getCompanyId(), $saved->getWarehouseId(), $line->getItemId(), $itemName, $movement->id, null, $userId)
            ?? throw new \LogicException("A pending entry always has its notice.");
        $entry->attachIncident((int) $incident->getId());
        $this->unidentified->save($entry);

        return $incident;
    }

    /**
     * The one "Identificar partida" notice of an item in a tank (stock_incidents allows one open notice
     * per tank, item and type): refreshed with what is still pending, resolved when nothing is (SU5).
     * Returns the notice, or null when there is nothing pending and no notice to close.
     */
    public function refreshNotice(int $companyId, int $warehouseId, int $itemId, string $itemName, ?int $movementId, ?string $closedBy, ?int $userId): ?StockIncidentEntity
    {
        [$in, $out] = $this->unidentified->pendingSplit($itemId, $warehouseId, $companyId);
        $open = $this->incidents->findOpen($warehouseId, $itemId, StockIncidentType::UNIDENTIFIED_SEMEN_LOT, $companyId);

        if (bccomp($in, '0', 4) === 0 && bccomp($out, '0', 4) === 0) {
            if ($open === null) {
                return null;
            }
            $open->resolve($closedBy ?? 'Resuelta: no quedan pajuelas sin partida.', $userId, now()->toIso8601String());

            return $this->incidents->save($open);
        }

        $parts = [];
        if (bccomp($in, '0', 4) > 0) {
            $parts[] = SemenUnidentifiedEntryEntity::format($in) . ' entraron sin partida';
        }
        if (bccomp($out, '0', 4) > 0) {
            $parts[] = SemenUnidentifiedEntryEntity::format($out) . ' salieron sin partida';
        }
        $detail = "Pajuelas de {$itemName}: " . implode(' y ', $parts) . '. Identificá su partida.';
        $metadata = ['pending_in' => $in, 'pending_out' => $out];

        if ($open !== null) {
            $open->refresh($movementId ?? $open->getStockMovementId(), $detail, $metadata);

            return $this->incidents->save($open);
        }

        return $this->incidents->save(new StockIncidentEntity(
            null, $companyId, $warehouseId, $itemId, StockIncidentType::UNIDENTIFIED_SEMEN_LOT, $movementId, $detail, $metadata, raisedBy: $userId,
        ));
    }

    /** SU8: the reversed line's pending straws are voided and its identified lots give back what they took. */
    private function undoPending(ItemDocumentEntity $saved, int $originalLineId, ?int $userId): void
    {
        $companyId = $saved->getCompanyId();
        $entry = $this->unidentified->findByLine($originalLineId, $companyId);
        if ($entry === null) {
            return;
        }
        foreach ($this->unidentified->identifications((int) $entry->getId(), $companyId) as $identification) {
            $delta = $entry->getDirection() > 0 ? bcmul($identification['quantity'], '-1', 4) : $identification['quantity'];
            $current = $this->lots->lockBalance($identification['lot_id'], $entry->getWarehouseId(), $companyId);
            $after = bcadd($current, $delta, 4);
            if (bccomp($after, '0', 4) < 0) {
                $code = $this->lots->findById($identification['lot_id'], $companyId)?->code ?? '';
                throw ItemDocumentException::on('domain', "No se puede anular: la partida {$code} ya no tiene en este termo las pajuelas que se le identificaron.");
            }
            $this->lots->setBalance($identification['lot_id'], $entry->getWarehouseId(), $companyId, $after);
        }
        $this->unidentified->voidIdentifications((int) $entry->getId(), $companyId);
        $entry->void();
        $this->unidentified->save($entry);

        $name = $this->items->findById($entry->getItemId(), $companyId)?->getName() ?? '';
        $this->refreshNotice($companyId, $entry->getWarehouseId(), $entry->getItemId(), $name, null, "Resuelta por la anulación N.º {$saved->getNumber()}.", $userId);
    }
}
