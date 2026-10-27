<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Enums\BullReplacementReason;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderBullHistoryRepository;

/**
 * Bull history of one service order, for the assistant and the MCP: every bull that served,
 * how and when he joined, when and why he left, and the gestations he is the confirmed or a
 * candidate sire of. Facts only: what was never declared reads "no registrado".
 */
final class GetServiceOrderBullHistoryUseCase
{
    private const SANITARY_LABELS = [
        'APT' => 'Apto',
        'UNFIT' => 'No apto',
        'IN_TREATMENT' => 'En tratamiento',
        'PENDING_EVALUATION' => 'Evaluación pendiente',
    ];

    public function __construct(
        private readonly IServiceOrderBullHistoryRepository $repository
    ) {
    }

    /**
     * @return array{order: array<string, mixed>, bulls: list<array<string, mixed>>}
     *
     * @throws ServiceOrderDomainException when the order does not belong to the company
     */
    public function __invoke(int $serviceOrderId, int $companyId): array
    {
        $order = $this->repository->findOrder($serviceOrderId, $companyId);
        if ($order === null) {
            throw ServiceOrderDomainException::domainError("La orden de servicio con ID {$serviceOrderId} no existe.");
        }

        $stays = $this->repository->findBullStays($serviceOrderId);
        $replacements = $this->repository->findReplacements($serviceOrderId);
        $sires = $this->repository->findGestationSires($serviceOrderId);
        $tags = array_column($stays, 'identification', 'bull_id');
        $today = date('Y-m-d');

        $bulls = array_map(function (array $stay) use ($order, $replacements, $sires, $tags, $today): array {
            $cameFor = $this->firstWhere($replacements, 'replacement_bull_id', $stay['bull_id']);
            $leftFor = $this->firstWhere($replacements, 'retired_bull_id', $stay['bull_id']);
            $ownSires = array_values(array_filter($sires, static fn (array $sire): bool => $sire['bull_id'] === $stay['bull_id']));
            $until = $stay['retired_at'] ?? $today;

            return [
                'bull_id' => $stay['bull_id'],
                'identification' => $stay['identification'],
                'category' => $stay['category'],
                'breed' => $stay['breed'],
                'status' => $stay['status'],
                'entry' => $this->entry($stay, $order, $cameFor, $tags),
                'exit' => $this->exit($stay, $leftFor, $tags),
                'days_in_service' => $stay['joined_at'] !== null
                    ? max(0, (int) floor((strtotime($until) - strtotime($stay['joined_at'])) / 86400))
                    : null,
                'confirmed_sire_of' => $this->females($ownSires, true),
                'candidate_sire_of' => $this->females($ownSires, false),
            ];
        }, $stays);

        return ['order' => $order + ['bull_count' => count($bulls)], 'bulls' => $bulls];
    }

    /**
     * @param array<int, string> $tags
     * @return array<string, mixed>
     */
    private function entry(array $stay, array $order, ?array $cameFor, array $tags): array
    {
        $kind = match (true) {
            $cameFor !== null => 'REPLACEMENT',
            $stay['joined_at'] !== null && $order['start_date'] !== null && $stay['joined_at'] > $order['start_date'] => $order['service_method'] === 'AI' ? 'REPASO' : 'ADDED',
            default => 'INITIAL',
        };
        $description = match ($kind) {
            'REPLACEMENT' => 'Suplente de ' . ($tags[$cameFor['retired_bull_id']] ?? "#{$cameFor['retired_bull_id']}")
                . ' (' . BullReplacementReason::from($cameFor['reason'])->label() . ')',
            'REPASO' => 'Toro de repaso de la inseminación artificial',
            'ADDED' => 'Sumado a la orden después del inicio del servicio',
            default => 'Toro con el que empezó el servicio',
        };

        return [
            'kind' => $kind,
            'date' => $stay['joined_at'],
            'description' => $description,
            'sanitary_status' => $stay['sanitary_status_at_entry'],
            'sanitary_label' => self::SANITARY_LABELS[$stay['sanitary_status_at_entry'] ?? ''] ?? 'Sin evaluación registrada',
            'scrotal_circumference' => $stay['scrotal_circumference'],
            'service_capacity' => $stay['service_capacity'],
        ];
    }

    /**
     * @param array<int, string> $tags
     * @return array<string, mixed>|null null while the bull is still serving
     */
    private function exit(array $stay, ?array $leftFor, array $tags): ?array
    {
        if ($stay['status'] === 'ACTIVE') {
            return null;
        }
        if ($stay['status'] === 'COMPLETED') {
            return [
                'kind' => 'SERVICE_ENDED', 'date' => $stay['retired_at'], 'reason' => null,
                'description' => 'Fin del servicio: salió con el retiro de la torada o el cierre de la orden',
                'notes' => null, 'destination_batch' => null, 'replaced_by' => null,
            ];
        }

        $reason = $stay['exit_reason'] ?? $leftFor['reason'] ?? null;
        $label = $reason !== null ? (BullReplacementReason::tryFrom($reason)?->label() ?? $reason) : 'Motivo no registrado';

        return [
            'kind' => $leftFor !== null ? 'REPLACED' : 'RETIRED',
            'date' => $stay['retired_at'],
            'reason' => $reason,
            'description' => ($leftFor !== null
                ? 'Sustituido por ' . ($tags[$leftFor['replacement_bull_id']] ?? "#{$leftFor['replacement_bull_id']}")
                : 'Retirado sin reemplazo') . ": {$label}",
            'notes' => $stay['exit_notes'] ?? $leftFor['notes'] ?? null,
            'destination_batch' => $stay['exit_destination_batch'] ?? $leftFor['destination_batch'] ?? null,
            'replaced_by' => $leftFor !== null ? ($tags[$leftFor['replacement_bull_id']] ?? null) : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $sires
     * @return list<array{female_id: int, identification: string, gestation_id: int}>
     */
    private function females(array $sires, bool $confirmed): array
    {
        return array_values(array_map(
            static fn (array $sire): array => [
                'female_id' => $sire['female_id'],
                'identification' => $sire['female_identification'],
                'gestation_id' => $sire['gestation_id'],
            ],
            array_filter($sires, static fn (array $sire): bool => $sire['is_confirmed'] === $confirmed)
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function firstWhere(array $rows, string $key, int $value): ?array
    {
        foreach ($rows as $row) {
            if ($row[$key] === $value) {
                return $row;
            }
        }

        return null;
    }
}
