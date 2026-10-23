<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Application\DTOs\Semen\SemenLotShareDTO;
use App\Application\Services\Stock\FieldErrorBag;
use App\Application\Services\Stock\SemenLotLedger;
use App\Core\Entities\SemenLotEntity;
use App\Core\Entities\SemenUnidentifiedEntryEntity;
use App\Core\Exceptions\SemenException;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\Interfaces\ISemenLotRepository;
use App\Core\Interfaces\ISemenUnidentifiedRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Identifies straws that moved without lot (SU3, SU4): the pending quantity goes to one or more lots
 * of the same item, existing or new, possibly in parts. The ledger is not touched (SU7): each lot's
 * balance in the tank moves and the identification is recorded. The notice closes when nothing is
 * pending (SU5). Locks the item row first and then the lots, like the ledger (DA11).
 */
final class IdentifySemenLotUseCase
{
    public function __construct(
        private readonly ISemenUnidentifiedRepository $unidentified,
        private readonly ISemenLotRepository $lots,
        private readonly IItemStockRepository $stocks,
        private readonly IItemRepository $items,
        private readonly SemenLotLedger $ledger,
    ) {
    }

    /** @param list<SemenLotShareDTO> $shares */
    public function __invoke(int $entryId, array $shares, int $companyId, ?int $userId): SemenUnidentifiedEntryEntity
    {
        return DB::transaction(function () use ($entryId, $shares, $companyId, $userId) {
            $entry = $this->unidentified->lockById($entryId, $companyId)
                ?? throw new ModelNotFoundException("Unidentified semen entry {$entryId} not found.");
            if (!$entry->isOpen()) {
                throw SemenException::on('domain', 'Estas pajuelas ya no tienen nada pendiente de identificar.');
            }
            $this->stocks->lockForMovement($entry->getWarehouseId(), $entry->getItemId(), $companyId);

            $errors = new FieldErrorBag();
            if ($shares === []) {
                $errors->add('lots', 'Indicá al menos una partida.');
            }
            $planned = [];
            $total = '0';
            foreach ($shares as $index => $share) {
                $prefix = "lots.{$index}";
                $lotId = null;
                $code = null;
                if ($share->semenLotId !== null) {
                    $lot = $this->lots->findById($share->semenLotId, $companyId);
                    if ($lot === null || $lot->itemId !== $entry->getItemId()) {
                        $errors->add("{$prefix}.semen_lot_id", 'La partida no es de este semen.');
                    } else {
                        $lotId = (int) $lot->id;
                    }
                } else {
                    try {
                        $code = SemenLotEntity::normalizeCode($share->lotCode, "{$prefix}.lot_code");
                    } catch (SemenException $e) {
                        $errors->add("{$prefix}.lot_code", $e->getMessage());
                    }
                }
                if (preg_match('/^\d+$/', $share->quantity) !== 1 || (int) $share->quantity <= 0) {
                    $errors->add("{$prefix}.quantity", 'Indicá cuántas pajuelas: un número entero mayor que cero.');
                    continue;
                }
                $total = bcadd($total, $share->quantity, 4);
                $planned[] = ['index' => $index, 'lot_id' => $lotId, 'code' => $code, 'date' => $share->collectionDate, 'quantity' => bcadd($share->quantity, '0', 4)];
            }
            if (bccomp($total, $entry->pending(), 4) > 0) {
                $errors->add('lots', 'Quedan ' . SemenUnidentifiedEntryEntity::format($entry->pending()) . ' pajuelas por identificar: no se puede asignar más.');
            }
            $errors->throwIfAny(SemenException::class);

            foreach ($planned as $share) {
                $lotId = $share['lot_id'] ?? (int) ($this->lots->findByCode($entry->getItemId(), (string) $share['code'], $companyId)
                    ?? $this->lots->create(new SemenLotEntity(null, $companyId, $entry->getItemId(), (string) $share['code'], $share['date'])))->id;
                $current = $this->lots->lockBalance($lotId, $entry->getWarehouseId(), $companyId);
                $after = $entry->getDirection() > 0 ? bcadd($current, $share['quantity'], 4) : bcsub($current, $share['quantity'], 4);
                if (bccomp($after, '0', 4) < 0) {
                    $code = $this->lots->findById($lotId, $companyId)?->code ?? '';
                    throw SemenException::on("lots.{$share['index']}.semen_lot_id", "La partida {$code} tiene " . SemenUnidentifiedEntryEntity::format($current) . ' pajuelas en este termo: no alcanza.');
                }
                $this->lots->setBalance($lotId, $entry->getWarehouseId(), $companyId, $after);
                $this->unidentified->addIdentification((int) $entry->getId(), $lotId, $share['quantity'], $userId, $companyId);
                $entry->identify($share['quantity']);
            }
            $this->unidentified->save($entry);

            $name = $this->items->findById($entry->getItemId(), $companyId)?->getName() ?? '';
            $this->ledger->refreshNotice($companyId, $entry->getWarehouseId(), $entry->getItemId(), $name, null, 'Resuelta: se identificó la partida de todas las pajuelas.', $userId);

            return $entry;
        });
    }
}
