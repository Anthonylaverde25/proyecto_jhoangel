<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\Inseminations\RegisterInseminationsDTO;
use App\Application\Services\Insemination\InseminationConsumption;
use App\Application\Services\Stock\FieldErrorBag;
use App\Application\Services\Stock\SemenLotLineGuard;
use App\Application\Services\Stock\SemenStorageRule;
use App\Application\Services\Stock\WarehouseGuard;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Entities\ServiceOrderInseminationEntity;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\InseminationException;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IServiceOrderInseminationRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Cargar inseminaciones" (semen S-B, IA6-IA8): the rows of the sheet become inseminations of the
 * order and, tank by tank, the consumption that takes their straws out. All or nothing: a broken
 * rule comes back on the cell that broke it.
 */
final class RegisterInseminationsUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly IServiceOrderInseminationRepository $inseminations,
        private readonly IItemRepository $items,
        private readonly IItemDocumentRepository $documents,
        private readonly WarehouseGuard $warehouses,
        private readonly SemenStorageRule $semen,
        private readonly SemenLotLineGuard $lots,
        private readonly InseminationConsumption $consumption,
    ) {
    }

    /**
     * @return array{order: ServiceOrderEntity, documents: list<\App\Core\Entities\ItemDocumentEntity>, incidents: list<\App\Core\Entities\StockIncidentEntity>, replayed: bool}
     */
    public function __invoke(RegisterInseminationsDTO $dto): array
    {
        $order = $this->openInseminationOrder($dto->serviceOrderId, $dto->companyId);

        $tanks = array_values(array_unique(array_map(fn ($row) => $row->warehouseId, $dto->rows)));
        $replayed = $this->replayed($dto->idempotencyKey, $tanks, $dto->companyId);
        if ($replayed !== []) {
            return ['order' => $order, 'documents' => $replayed, 'incidents' => [], 'replayed' => true];
        }

        $drafts = $this->validate($order, $dto);

        return DB::transaction(function () use ($order, $dto, $drafts): array {
            $byTank = [];
            foreach ($drafts as $row => $draft) {
                $byTank[$draft->getWarehouseId()][$row] = $this->inseminations->create($draft);
            }

            $documents = [];
            $incidents = [];
            foreach ($byTank as $warehouseId => $byRow) {
                $result = $this->consumption->issue(
                    $order,
                    (int) $warehouseId,
                    $byRow,
                    "{$dto->idempotencyKey}:{$warehouseId}",
                    $dto->userId,
                    "Inseminación · {$order->getCode()}"
                );
                $documents[] = $result->document;
                array_push($incidents, ...$result->incidents);
            }

            $females = array_values(array_unique(array_map(fn (ServiceOrderInseminationEntity $i) => $i->getFemaleCaravanId(), $drafts)));
            $this->inseminations->clearNotInseminatedReason((int) $order->getId(), $females);

            $fresh = $this->orders->findById((int) $order->getId(), $dto->companyId) ?? $order;

            return ['order' => $fresh, 'documents' => $documents, 'incidents' => $incidents, 'replayed' => false];
        });
    }

    private function openInseminationOrder(int $orderId, int $companyId): ServiceOrderEntity
    {
        $order = $this->orders->findById($orderId, $companyId);
        if ($order === null) {
            throw InseminationException::on('domain', 'La orden de servicio no existe.');
        }
        if (!$order->isInsemination()) {
            throw InseminationException::on('domain', "La orden {$order->getCode()} es de monta natural: no lleva inseminaciones.");
        }
        if (!in_array($order->getStatus(), [ServiceOrderStatus::APPROVED, ServiceOrderStatus::SERVICE_ENDED], true)) {
            throw InseminationException::on('domain', "La orden {$order->getCode()} está cerrada: no admite inseminaciones.");
        }

        return $order;
    }

    /**
     * Documents already recorded with this request's key (DS8).
     *
     * @param list<int> $tanks
     * @return list<\App\Core\Entities\ItemDocumentEntity>
     */
    private function replayed(string $key, array $tanks, int $companyId): array
    {
        $found = [];
        foreach ($tanks as $warehouseId) {
            $document = $this->documents->findByIdempotencyKey("{$key}:{$warehouseId}", $companyId);
            if ($document !== null) {
                $found[] = $document;
            }
        }

        return $found;
    }

    /**
     * Every rule of every row, so the sheet marks all the offending cells at once.
     *
     * @return array<int, ServiceOrderInseminationEntity> by sheet row
     */
    private function validate(ServiceOrderEntity $order, RegisterInseminationsDTO $dto): array
    {
        $errors = new FieldErrorBag();
        if ($dto->rows === []) {
            throw InseminationException::on('rows', 'Marcá al menos un vientre para registrar.');
        }

        $females = array_flip($order->getFemaleCaravanIds());
        $from = substr($order->getActualStartDate() ?? $order->getPlannedStartDate(), 0, 10);
        $today = now()->toDateString();
        $until = $order->getStatus() === ServiceOrderStatus::SERVICE_ENDED && $order->getActualEndDate() !== null
            ? min($today, substr($order->getActualEndDate(), 0, 10))
            : $today;

        $pairs = [];
        foreach ($dto->rows as $row) {
            $pairs[] = [$row->femaleCaravanId, $row->inseminatedOn];
        }
        $existing = array_flip($this->inseminations->liveDuplicates((int) $order->getId(), $pairs));

        $seen = [];
        $drafts = [];
        foreach ($dto->rows as $index => $row) {
            $prefix = "rows.{$index}";
            if (!isset($females[$row->femaleCaravanId])) {
                $errors->add("{$prefix}.female_caravan_id", 'El vientre no es de esta orden.');
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $row->inseminatedOn) !== 1) {
                $errors->add("{$prefix}.inseminated_on", 'Indicá la fecha de la inseminación.');
            } elseif ($row->inseminatedOn < $from) {
                $errors->add("{$prefix}.inseminated_on", "La fecha es anterior al inicio del servicio ({$from}).");
            } elseif ($row->inseminatedOn > $until) {
                $errors->add("{$prefix}.inseminated_on", $until === $today
                    ? 'La fecha no puede ser futura.'
                    : "El servicio terminó el {$until}: la fecha no puede ser posterior.");
            }
            $pair = "{$row->femaleCaravanId}|{$row->inseminatedOn}";
            if (isset($seen[$pair]) || isset($existing[$pair])) {
                $errors->add("{$prefix}.inseminated_on", 'El vientre ya tiene una inseminación ese día.');
            }
            $seen[$pair] = true;
            if ($row->strawsUsed < 1) {
                $errors->add("{$prefix}.straws_used", 'Indicá al menos una pajuela.');
            }

            $item = $this->items->findById($row->itemId, $dto->companyId);
            if ($item === null || !$this->semen->isSemen($item)) {
                $errors->add("{$prefix}.item_id", 'Elegí un semen de la empresa.');
                continue;
            }
            if (!$item->isActive()) {
                $errors->add("{$prefix}.item_id", "{$item->getName()} está inactivo.");
            }
            $lotId = $this->lots->check($prefix, $item, $row->semenLotId, true, $errors);
            $warehouse = $this->warehouses->active($dto->companyId, $row->warehouseId, "{$prefix}.warehouse_id", $errors);
            if ($warehouse !== null) {
                $this->semen->check($item, $warehouse, "{$prefix}.warehouse_id", $errors);
            }

            $drafts[$index] = new ServiceOrderInseminationEntity(
                null,
                $dto->companyId,
                (int) $order->getId(),
                $row->femaleCaravanId,
                $row->inseminatedOn,
                $row->itemId,
                $lotId,
                max(1, $row->strawsUsed),
                $row->warehouseId,
                notes: $row->notes,
                createdBy: $dto->userId,
            );
        }
        $errors->throwIfAny(InseminationException::class);

        return $drafts;
    }
}
