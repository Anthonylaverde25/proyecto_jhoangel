<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\Services\Insemination\InseminationConsumption;
use App\Application\Services\Stock\StockLedger;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\InseminationException;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IServiceOrderInseminationRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * Voids an insemination loaded by mistake (semen S-B, IA9). The ledger is never edited: the
 * consumption of its load is reversed whole and issued again with the inseminations of that load
 * that still stand, in one transaction. A female left without any is simply not inseminated (IA12).
 */
final class VoidInseminationUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly IServiceOrderInseminationRepository $inseminations,
        private readonly IItemDocumentRepository $documents,
        private readonly StockLedger $ledger,
        private readonly InseminationConsumption $consumption,
    ) {
    }

    public function __invoke(int $orderId, int $inseminationId, string $reason, string $idempotencyKey, int $companyId, ?int $userId): ServiceOrderEntity
    {
        $order = $this->orders->findById($orderId, $companyId)
            ?? throw InseminationException::on('domain', 'La orden de servicio no existe.');
        if (!in_array($order->getStatus(), [ServiceOrderStatus::APPROVED, ServiceOrderStatus::SERVICE_ENDED], true)) {
            throw InseminationException::on('domain', "La orden {$order->getCode()} está cerrada: sus inseminaciones ya no se anulan.");
        }
        if (trim($reason) === '') {
            throw InseminationException::on('reason', 'Indicá el motivo de la anulación.');
        }

        return DB::transaction(function () use ($order, $inseminationId, $reason, $idempotencyKey, $companyId, $userId): ServiceOrderEntity {
            $insemination = $this->inseminations->lockById($inseminationId, $companyId);
            if ($insemination === null || $insemination->getServiceOrderId() !== $order->getId()) {
                throw InseminationException::on('domain', 'La inseminación no es de esta orden.');
            }
            if (!$insemination->isLive()) {
                // A retried request: the first one already voided it.
                if ($this->documents->findByIdempotencyKey("{$idempotencyKey}:reversal", $companyId) !== null) {
                    return $order;
                }
                throw InseminationException::on('domain', 'La inseminación ya está anulada.');
            }

            $insemination->void($reason, $userId, now()->toDateTimeString());
            $this->inseminations->save($insemination);

            $lineId = $insemination->getItemDocumentLineId();
            $documentId = $lineId === null ? null : $this->inseminations->documentIdOfLine($lineId, $companyId);
            $original = $documentId === null ? null : $this->documents->findById($documentId, $companyId);
            if ($original !== null) {
                $lineIds = array_map(fn ($line) => (int) $line->getId(), $original->getLines());
                $remaining = $this->inseminations->liveByLines($lineIds, $companyId);

                $this->ledger->reverse(
                    $original,
                    "el consumo N.º {$original->getNumber()}",
                    "Inseminación anulada: {$reason}",
                    now()->toDateTimeString(),
                    "{$idempotencyKey}:reversal",
                    $userId
                );

                if ($remaining !== []) {
                    $this->consumption->issue(
                        $order,
                        $original->getWarehouseId(),
                        $remaining,
                        "{$idempotencyKey}:reissue",
                        $userId,
                        "Inseminación · {$order->getCode()} (rehecho al anular una inseminación del consumo N.º {$original->getNumber()})"
                    );
                }
            }

            return $this->orders->findById((int) $order->getId(), $companyId) ?? $order;
        });
    }
}
