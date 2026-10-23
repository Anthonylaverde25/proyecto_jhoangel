<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Application\DTOs\Semen\SemenReceiptDTO;
use App\Application\Services\Stock\FieldErrorBag;
use App\Application\Services\Stock\LedgerResult;
use App\Application\Services\Stock\SemenItemProvisioner;
use App\Application\Services\Stock\StockLedger;
use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Entities\SemenLotEntity;
use App\Core\Entities\SemenSireEntity;
use App\Core\Enums\ItemDocumentType;
use App\Core\Enums\SemenType;
use App\Core\Exceptions\SemenException;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IProviderRepository;
use App\Core\Interfaces\ISemenLotRepository;
use App\Core\Interfaces\ISemenSireRepository;
use App\Core\Interfaces\IUnitOfMeasureRepository;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\ValueObjects\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * The semen entry (S14, plan 5.5): straws into one tank, with no provider document behind. Each line
 * names a sire, a type and, when known, the lot; the item and the lot are created when missing (SE1,
 * DA2, DA3). A line without lot leaves its straws pending with a notice (SL6). Everything in one
 * transaction: if the ledger refuses, nothing created here remains.
 */
final class RegisterSemenReceiptUseCase
{
    public function __construct(
        private readonly IItemDocumentRepository $documents,
        private readonly IWarehouseRepository $warehouses,
        private readonly IProviderRepository $providers,
        private readonly ISemenSireRepository $sires,
        private readonly ISemenLotRepository $lots,
        private readonly SemenItemProvisioner $provisioner,
        private readonly StockLedger $ledger,
        private readonly IUnitOfMeasureRepository $units,
    ) {
    }

    public function __invoke(SemenReceiptDTO $dto, int $companyId): LedgerResult
    {
        $existing = $this->documents->findByIdempotencyKey($dto->idempotencyKey, $companyId);
        if ($existing !== null) {
            return new LedgerResult($existing, [], true);
        }

        $errors = new FieldErrorBag();
        $today = now()->toDateString();
        if (substr($dto->occurredAt, 0, 10) > $today) {
            $errors->add('occurred_at', 'La fecha no puede ser futura.');
        }
        $this->checkTank($dto->warehouseId, $companyId, $errors);
        $this->checkProvider($dto->providerId, $companyId, $errors);
        if ($dto->lines === []) {
            $errors->add('lines', 'El ingreso no tiene renglones.');
        }

        /** @var list<array{sire: SemenSireEntity, type: SemenType, lot: ?string, date: ?string, quantity: string, cost: string}> $lines */
        $lines = [];
        $seen = [];
        foreach ($dto->lines as $index => $line) {
            $prefix = "lines.{$index}";
            $sire = $line->sireId === null ? null : $this->sires->findById($line->sireId, $companyId);
            if ($sire === null || !$sire->isActive) {
                $errors->add("{$prefix}.semen_sire_id", $sire === null ? 'Elegí el padre.' : "{$sire->name} está desactivado.");
            }
            $type = $line->semenType === null ? null : SemenType::tryFrom($line->semenType);
            if ($type === null) {
                $errors->add("{$prefix}.semen_type", 'Elegí el tipo de semen.');
            }
            $lot = null;
            if ($line->lotCode !== null) {
                try {
                    $lot = SemenLotEntity::normalizeCode($line->lotCode);
                } catch (SemenException $e) {
                    $errors->add("{$prefix}.lot_code", $e->getMessage());
                }
            }
            if ($line->collectionDate !== null && $line->collectionDate > $today) {
                $errors->add("{$prefix}.collection_date", 'La fecha de colección no puede ser futura.');
            }
            if (preg_match('/^\d+$/', $line->quantity) !== 1 || (int) $line->quantity <= 0) {
                $errors->add("{$prefix}.quantity", 'Indicá cuántas pajuelas: un número entero mayor que cero.');
            }
            if (!is_numeric($line->unitCost) || (float) $line->unitCost < 0) {
                $errors->add("{$prefix}.unit_cost", 'Indicá el costo por pajuela (0 si no se conoce).');
            }
            if ($sire === null || $type === null) {
                continue;
            }
            // SE2: one line per sire, type and lot.
            $key = "{$sire->id}|{$type->value}|" . ($lot ?? '');
            if (isset($seen[$key])) {
                $errors->add("{$prefix}.lot_code", 'Este padre, tipo y partida ya están en otro renglón: sumá las pajuelas en uno.');
                continue;
            }
            $seen[$key] = true;
            $lines[$index] = ['sire' => $sire, 'type' => $type, 'lot' => $lot, 'date' => $line->collectionDate, 'quantity' => $line->quantity, 'cost' => $line->unitCost];
        }
        $errors->throwIfAny(SemenException::class);

        return DB::transaction(function () use ($dto, $companyId, $lines) {
            $strawUnitId = $this->strawUnitId($companyId);
            $entities = [];
            foreach ($lines as $index => $line) {
                $item = $this->provisioner->provide($line['sire'], $line['type'], $companyId);
                $lotId = null;
                if ($line['lot'] !== null) {
                    $lotId = ($this->lots->findByCode((int) $item->getId(), $line['lot'], $companyId)
                        ?? $this->lots->create(new SemenLotEntity(null, $companyId, (int) $item->getId(), $line['lot'], $line['date'])))->id;
                }
                $quantity = Decimal::of($line['quantity']);
                $cost = Decimal::of($line['cost']);
                $entities[] = new ItemDocumentLineEntity(
                    count($entities) + 1,
                    (int) $item->getId(),
                    $quantity->toScale(4),
                    $strawUnitId,
                    null,
                    $quantity->toScale(4),
                    $cost->toScale(6),
                    $cost->toScale(6),
                    $quantity->mul($cost)->round(4)->toScale(4),
                    semenLotId: $lotId,
                );
            }

            return $this->ledger->receive(new ItemDocumentEntity(
                null, $companyId, null, ItemDocumentType::SEMEN_RECEIPT, (int) $dto->warehouseId, $dto->occurredAt, $entities,
                notes: $dto->notes, idempotencyKey: $dto->idempotencyKey, createdBy: $dto->createdBy, providerId: $dto->providerId,
            ), $dto->createdBy);
        });
    }

    private function checkTank(?int $warehouseId, int $companyId, FieldErrorBag $errors): void
    {
        $warehouse = $warehouseId === null ? null : $this->warehouses->findById($warehouseId, $companyId);
        if ($warehouse === null) {
            $errors->add('warehouse_id', 'Elegí el termo.');
        } elseif (!$warehouse->isCryoTank()) {
            $errors->add('warehouse_id', "{$warehouse->label()} no es un termo: el semen sólo se guarda en termos.");
        } elseif (!$warehouse->isActive) {
            $errors->add('warehouse_id', "El termo {$warehouse->code} está inactivo.");
        }
    }

    private function checkProvider(?int $providerId, int $companyId, FieldErrorBag $errors): void
    {
        if ($providerId === null) {
            return;
        }
        $provider = $this->providers->findById($providerId);
        if ($provider === null || $provider->getCompanyId() !== $companyId) {
            $errors->add('provider_id', 'El proveedor no existe en esta empresa.');
        }
    }

    private function strawUnitId(int $companyId): int
    {
        foreach ($this->units->findAll($companyId, true) as $unit) {
            if ($unit->getCode() === 'STRAW') {
                return (int) $unit->getId();
            }
        }
        throw SemenException::on('domain', 'Falta la unidad Pajuela en esta empresa.');
    }
}
