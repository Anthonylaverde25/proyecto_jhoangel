<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\WeaningOrderAnimalEntity;
use App\Core\Entities\WeaningOrderDeviationEntity;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\WeaningDeviationKind;
use App\Core\Enums\WeaningType;
use App\Core\Interfaces\IWeaningOrderDeviationRepository;
use DateTimeImmutable;

/**
 * Writes what a weaning did differently from its order, calf by calf, for the audit tab. It never
 * blocks nor changes anything: every caller has already decided; this only leaves it on record.
 * An order registered after the fact planned nothing, so nothing deviates from it.
 */
final class WeaningDeviationRecorder
{
    /** Weaned this many days after the planned date or more is late. */
    public const LATE_AFTER_DAYS = 7;

    public function __construct(private readonly IWeaningOrderDeviationRepository $deviations)
    {
    }

    /**
     * Declared at the desk while weaning: a calf sent elsewhere, a different weaning type.
     *
     * @param list<int> $caravanIds the calves weaned now
     */
    public function declaredAtWeaning(WeaningOrderEntity $order, array $caravanIds, ?WeaningType $declaredType, string $weaningDate, ?int $userId): void
    {
        if (!$this->hasPlan($order)) {
            return;
        }

        $at = new DateTimeImmutable(substr($weaningDate, 0, 10));
        $rows = [];

        foreach ($this->lines($order, $caravanIds) as $line) {
            if ($line->isRedirected()) {
                $rows[] = WeaningOrderDeviationEntity::record(
                    $order,
                    $line,
                    WeaningDeviationKind::DESTINATION,
                    $order->destinationByKey((string) $line->getPlannedDestinationKey())?->getLabel(),
                    $order->destinationByKey((string) $line->getDestinationKey())?->getLabel(),
                    ['planned_key' => $line->getPlannedDestinationKey(), 'actual_key' => $line->getDestinationKey()],
                    $at,
                    $userId
                );
            }

            if ($declaredType !== null && $declaredType !== $order->getWeaningType()) {
                $rows[] = WeaningOrderDeviationEntity::record(
                    $order,
                    $line,
                    WeaningDeviationKind::WEANING_TYPE,
                    $order->getWeaningType()?->label() ?? 'Sin declarar',
                    $declaredType->label(),
                    ['planned' => $order->getWeaningType()?->value, 'declared' => $declaredType->value],
                    $at,
                    $userId
                );
            }
        }

        $this->deviations->recordMany($rows);
    }

    /**
     * Any execution, from the screen or a sheet: a C/S other than the one the order declared, and
     * a weaning well after the planned date.
     *
     * @param list<int> $weanedCaravanIds
     * @param array<int, array{planned: string, actual: string, metadata: array<string, mixed>}> $categoryByCaravanId
     */
    public function atExecution(WeaningOrderEntity $order, array $weanedCaravanIds, string $weaningDate, array $categoryByCaravanId, ?int $userId): void
    {
        if (!$this->hasPlan($order)) {
            return;
        }

        $at = new DateTimeImmutable(substr($weaningDate, 0, 10));
        $planned = new DateTimeImmutable(substr($order->getWeaningDate(), 0, 10));
        $daysLate = (int) $planned->diff($at)->format('%r%a');
        $rows = [];

        foreach ($this->lines($order, $weanedCaravanIds) as $line) {
            $category = $categoryByCaravanId[$line->getCaravanId()] ?? null;

            if ($category !== null) {
                $rows[] = WeaningOrderDeviationEntity::record(
                    $order, $line, WeaningDeviationKind::CATEGORY, $category['planned'], $category['actual'], $category['metadata'], $at, $userId
                );
            }

            if ($daysLate >= self::LATE_AFTER_DAYS) {
                $rows[] = WeaningOrderDeviationEntity::record(
                    $order,
                    $line,
                    WeaningDeviationKind::LATE,
                    'Planificada ' . $planned->format('d/m/Y'),
                    'Destetada ' . $at->format('d/m/Y') . " · +{$daysLate} d",
                    ['days_late' => $daysLate],
                    $at,
                    $userId
                );
            }
        }

        $this->deviations->recordMany($rows);
    }

    /**
     * The calves still pending when the order was closed incomplete.
     *
     * @param WeaningOrderAnimalEntity[] $pending
     */
    public function notWeaned(WeaningOrderEntity $order, array $pending, string $reason, ?int $userId): void
    {
        if (!$this->hasPlan($order)) {
            return;
        }

        $at = new DateTimeImmutable();
        $rows = array_map(fn (WeaningOrderAnimalEntity $line) => WeaningOrderDeviationEntity::record(
            $order,
            $line,
            WeaningDeviationKind::NOT_WEANED,
            'Destetar con la orden',
            'Cerrada sin destetarla: «' . trim($reason) . '»',
            ['reason' => trim($reason)],
            $at,
            $userId
        ), array_values(array_filter($pending, fn (WeaningOrderAnimalEntity $line) => $line->getId() !== null)));

        $this->deviations->recordMany($rows);
    }

    private function hasPlan(WeaningOrderEntity $order): bool
    {
        return $order->getId() !== null && $order->getKind() !== TransferOrderKind::REGISTERED;
    }

    /**
     * @param list<int> $caravanIds
     * @return WeaningOrderAnimalEntity[]
     */
    private function lines(WeaningOrderEntity $order, array $caravanIds): array
    {
        $lines = [];

        foreach ($caravanIds as $caravanId) {
            $line = $order->animalByCaravanId((int) $caravanId);

            if ($line !== null && $line->getId() !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
