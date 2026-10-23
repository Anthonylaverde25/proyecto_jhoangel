<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\ItemEntity;
use App\Core\Interfaces\ISemenLotRepository;

/**
 * The lot of a stock line (SL1, SL2): a semen line names a lot of its own item; any other line names
 * none. Only the semen entry and the insemination of an AI order may leave it empty (SL6). Straws without lot
 * are not transferred, counted or discarded until identified (SU6).
 */
final class SemenLotLineGuard
{
    public function __construct(
        private readonly SemenStorageRule $semen,
        private readonly ISemenLotRepository $lots,
    ) {
    }

    /** The lot id to record, or null; errors under "{prefix}.semen_lot_id". */
    public function check(string $prefix, ItemEntity $item, ?int $lotId, bool $mayBeMissing, FieldErrorBag $errors): ?int
    {
        $field = $prefix === '' ? 'semen_lot_id' : "{$prefix}.semen_lot_id";
        if (!$this->semen->isSemen($item)) {
            if ($lotId !== null) {
                $errors->add($field, "{$item->getName()} no es semen: no lleva partida.");
            }

            return null;
        }
        if ($lotId === null) {
            if (!$mayBeMissing) {
                $errors->add($field, 'Elegí la partida. Si hay pajuelas sin identificar, identificalas primero.');
            }

            return null;
        }
        $lot = $this->lots->findById($lotId, $item->getCompanyId());
        if ($lot === null || $lot->itemId !== $item->getId()) {
            $errors->add($field, "La partida no es de {$item->getName()}.");

            return null;
        }

        return (int) $lot->id;
    }
}
