<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderIncidentRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\EmptyVerdictValidity;

/**
 * Everything the diagnosis screens need about an order: summary, rounds, every female with her
 * current and previous verdict, and the incidents.
 */
final class GetServiceOrderDiagnosisUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly IServiceOrderIncidentRepository $incidentRepository
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $orderId, int $companyId): array
    {
        $order = $this->serviceOrderRepository->findById($orderId, $companyId);
        if ($order === null) {
            throw ServiceOrderDomainException::domainError('La orden de servicio no existe.');
        }

        $detail = $this->serviceOrderRepository->getDiagnosisDetail($orderId, $companyId);
        $today = date('Y-m-d');

        // AI without repaso (semen S-B): the straws are the only exposure (IA11), and a female never
        // inseminated is not scanned (IA12).
        $strawsOnly = $order->isInsemination() && !$order->hadRepasoBulls();
        $lastInsemination = [];
        $notInseminatedReason = [];
        foreach ($order->getFemaleExposures() as $exposure) {
            $lastInsemination[(int) $exposure['female_caravan_id']] = $exposure['last_insemination_on'] ?? null;
            $notInseminatedReason[(int) $exposure['female_caravan_id']] = $exposure['not_inseminated_reason'] ?? null;
        }

        $females = array_map(static function (array $female) use ($today, $strawsOnly, $lastInsemination, $notInseminatedReason): array {
            $id = (int) $female['female_caravan_id'];
            $last = $lastInsemination[$id] ?? null;
            $isEmpty = $female['reproductive_status'] === 'EMPTY';
            $female['last_insemination_on'] = $last;
            $female['not_inseminated_reason'] = $notInseminatedReason[$id] ?? null;
            $female['is_not_inseminated'] = $strawsOnly && $last === null;
            // The day from which an empty is final: her last insemination under AI without repaso (IA11).
            $female['empty_final_after'] = $strawsOnly ? $last : null;

            if ($strawsOnly) {
                $final = EmptyVerdictValidity::isFinalAfterInsemination($female['diagnosed_at'], $last);
                $female['is_provisional_empty'] = $isEmpty && !$final;
                $female['is_final_if_closed_today'] = $female['is_not_inseminated'] || ($isEmpty ? $final : $female['reproductive_status'] !== 'UNCHECKED');

                return $female;
            }

            // Provisional: scanned empty while still exposed. Closing today would or would not
            // make it final depending on the day of the verdict.
            $female['is_provisional_empty'] = $isEmpty
                && !EmptyVerdictValidity::isFinal($female['diagnosed_at'], $female['exposed_until']);
            $female['is_final_if_closed_today'] = $isEmpty
                ? EmptyVerdictValidity::isFinal($female['diagnosed_at'], $female['exposed_until'], $today)
                : !in_array($female['reproductive_status'], ['UNCHECKED'], true);

            return $female;
        }, $detail['females']);

        return [
            'order_id' => $orderId,
            'status' => $order->getStatus()->value,
            'service_method' => $order->getServiceMethod()->value,
            'suggested_diagnosis_date' => $order->getSuggestedDiagnosisDate(),
            'summary' => $order->getDiagnosisSummary()->toArray(),
            'pending_if_closed_today' => $order->getDiagnosisSummary($today)->pendingCount(),
            'rounds' => $detail['rounds'],
            'females' => $females,
            'incidents' => $this->incidentRepository->listByOrder($orderId),
        ];
    }
}
