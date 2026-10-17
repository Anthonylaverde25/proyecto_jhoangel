<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\IBatchOverviewRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IEntryOrderRepository;
use App\Core\Interfaces\ISanitaryExposureRepository;
use App\Core\ValueObjects\BatchAlert;
use App\Core\ValueObjects\BatchOverview;

/**
 * The batch page summary: composition, reproductive state (Cría only) and the alerts that need
 * someone to act, each pointing at the document behind it. Null when the batch does not exist
 * for the active company.
 */
final class GetBatchOverviewUseCase
{
    private const ORDER_LABELS = [
        'transfer_order' => 'Orden de traslado',
        'weaning_order' => 'Orden de destete',
        'birth_order' => 'Orden de parición',
    ];

    public function __construct(
        private readonly IBatchRepository $batches,
        private readonly IBatchOverviewRepository $overview,
        private readonly ICaravanRepository $caravans,
        private readonly IEntryOrderRepository $entryOrders,
        private readonly ICompanyContext $companyContext,
        private readonly ISanitaryExposureRepository $sanitaryExposures,
    ) {
    }

    public function __invoke(int $batchId): ?BatchOverview
    {
        $batch = $this->batches->findById($batchId);

        if ($batch === null) {
            return null;
        }

        $companyId = (int) $this->companyContext->getCompanyId();
        $overdue = $this->overview->overdueBirths($batchId, $companyId);

        $reproductive = null;
        if ($batch->isCria()) {
            $counts = $this->overview->pregnancyCounts($batchId);
            $reproductive = [
                'females' => $counts['females'],
                'pregnant' => $counts['pregnant'],
                'without_gestation' => $counts['females'] - $counts['pregnant'],
                'overdue' => array_sum(array_column($overdue, 'count')),
            ];
        }

        $alerts = [
            ...$this->overdueAlerts($overdue),
            ...$this->sanitaryExposureAlerts($batchId, $companyId),
            ...$this->incidentAlerts($batchId, $companyId),
            ...$this->incomingAlerts($batchId, $companyId),
            ...$this->serviceAlerts($batchId),
            ...$this->pendingOrderAlerts($batchId, $companyId),
            ...$this->unweighedAlerts($batch),
        ];
        // Stable sort: within a severity, the order above is kept.
        usort($alerts, fn (BatchAlert $a, BatchAlert $b) => $a->rank() <=> $b->rank());

        return new BatchOverview(
            batchId: $batchId,
            sexComposition: $this->overview->sexComposition($batchId),
            categoryComposition: $this->overview->categoryComposition($batchId),
            lastWeighingDate: $this->caravans->getLatestWeighingDateByBatch($batchId),
            reproductive: $reproductive,
            alerts: $alerts,
        );
    }

    /**
     * @param list<array{birth_order_id: int, code: string, count: int}> $overdue
     * @return list<BatchAlert>
     */
    private function overdueAlerts(array $overdue): array
    {
        return array_map(fn (array $row) => new BatchAlert(
            type: BatchAlert::OVERDUE_BIRTH,
            severity: BatchAlert::SEVERITY_ERROR,
            message: $row['count'] === 1
                ? '1 hembra con parto vencido: requiere revisión'
                : "{$row['count']} hembras con parto vencido: requieren revisión",
            count: $row['count'],
            linkKind: 'birth_order',
            linkId: $row['birth_order_id'],
            linkCode: $row['code'],
        ), $overdue);
    }

    /**
     * Females of this batch that served with a bull found positive to a contagious pathogen.
     *
     * @return list<BatchAlert>
     */
    private function sanitaryExposureAlerts(int $batchId, int $companyId): array
    {
        return array_map(fn (array $row) => new BatchAlert(
            type: BatchAlert::SANITARY_EXPOSURE,
            severity: BatchAlert::SEVERITY_ERROR,
            message: ($row['count'] === 1
                ? '1 vientre de este lote estuvo'
                : "{$row['count']} vientres de este lote estuvieron")
                . " con {$row['bull_tag']} (positivo a {$row['pathogen']}): seguir en el tacto",
            count: $row['count'],
            linkKind: 'sanitary_exposure_alert',
            linkId: $row['alert_id'],
            linkCode: $row['bull_tag'],
        ), $this->sanitaryExposures->openAlertsInBatch($batchId, $companyId));
    }

    /**
     * @return list<BatchAlert>
     */
    private function incidentAlerts(int $batchId, int $companyId): array
    {
        return array_map(fn (array $row) => new BatchAlert(
            type: BatchAlert::ENTRY_INCIDENT,
            severity: BatchAlert::SEVERITY_WARNING,
            message: $row['count'] === 1
                ? '1 novedad abierta en la compra'
                : "{$row['count']} novedades abiertas en la compra",
            count: $row['count'],
            linkKind: 'entry_order',
            linkId: $row['entry_order_id'],
            linkCode: $row['code'],
        ), $this->overview->openEntryIncidents($batchId, $companyId));
    }

    /**
     * @return list<BatchAlert>
     */
    private function incomingAlerts(int $batchId, int $companyId): array
    {
        $incoming = $this->entryOrders->incomingSummariesByBatch([$batchId], $companyId)[$batchId] ?? [];

        return array_map(function (array $order): BatchAlert {
            $parts = [];
            if ($order['in_transit_count'] > 0) {
                $parts[] = "{$order['in_transit_count']} cab. en tránsito";
            }
            if ($order['pending_count'] > 0) {
                $parts[] = "{$order['pending_count']} cab. por identificar";
            }
            $detail = $parts !== [] ? implode(' · ', $parts) : $order['status_label'];

            return new BatchAlert(
                type: BatchAlert::INCOMING_PURCHASE,
                severity: BatchAlert::SEVERITY_WARNING,
                message: "Compra en curso: {$detail}",
                count: $order['in_transit_count'] + $order['pending_count'],
                linkKind: 'entry_order',
                linkId: $order['id'],
                linkCode: $order['code'],
            );
        }, $incoming);
    }

    /**
     * @return list<BatchAlert>
     */
    private function serviceAlerts(int $batchId): array
    {
        $service = $this->overview->activeServiceOrder($batchId);

        if ($service === null) {
            return [];
        }

        $state = $service['status'] === 'APPROVED' ? 'en curso' : 'en borrador';

        return [new BatchAlert(
            type: BatchAlert::ACTIVE_SERVICE,
            severity: BatchAlert::SEVERITY_INFO,
            message: "Servicio {$state}" . ($service['planned_start_date'] !== null ? " desde {$service['planned_start_date']}" : ''),
            linkKind: 'service_order',
            linkId: $service['id'],
            linkCode: $service['code'],
        )];
    }

    /**
     * @return list<BatchAlert>
     */
    private function pendingOrderAlerts(int $batchId, int $companyId): array
    {
        return array_map(fn (array $order) => new BatchAlert(
            type: BatchAlert::PENDING_ORDER,
            severity: BatchAlert::SEVERITY_INFO,
            message: (self::ORDER_LABELS[$order['kind']] ?? 'Orden') . " con {$order['pending_count']} cab. pendientes en este lote",
            count: $order['pending_count'],
            linkKind: $order['kind'],
            linkId: $order['id'],
            linkCode: $order['code'],
        ), $this->overview->pendingOrders($batchId, $companyId));
    }

    /**
     * @return list<BatchAlert>
     */
    private function unweighedAlerts(BatchEntity $batch): array
    {
        $unweighed = (int) $batch->getCaravansCount() - (int) $batch->getWeighedCount();

        if ($unweighed <= 0) {
            return [];
        }

        return [new BatchAlert(
            type: BatchAlert::UNWEIGHED,
            severity: BatchAlert::SEVERITY_INFO,
            message: $unweighed === 1 ? '1 caravana sin peso registrado' : "{$unweighed} caravanas sin peso registrado",
            count: $unweighed,
        )];
    }
}
