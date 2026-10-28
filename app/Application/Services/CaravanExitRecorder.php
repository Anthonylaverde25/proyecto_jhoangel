<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\ExitOrders\ExitLineDTO;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Enums\CalfAtFootDecision;
use App\Core\Enums\WeighingSource;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Exceptions\CaravanExitException;
use App\Core\Exceptions\ExitOrderDomainException;
use App\Core\Exceptions\InvalidWeighingException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanDeathRepository;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\CaravanWeighingRecorder;
use App\Core\ValueObjects\ExitSnapshot;
use App\Core\ValueObjects\TerminalBatchTypes;
use App\Core\ValueObjects\WeighingReading;

/**
 * What an animal leaving the company goes through, shared by executing an issued order and by
 * registering an exit that already happened (egreso plan v5, 05 §5). Every row is checked before
 * anything is written (E8); then each animal is taken out in the domain, weighed when it brought a
 * weight, and moved to the exit batch with a SALE movement. The caller holds the transaction.
 */
final class CaravanExitRecorder
{
    /** @var array<int, ?BatchEntity> */
    private array $batches = [];

    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICaravanLineageRepository $lineageRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly ICaravanDeathRepository $deathRepository,
        private readonly OpenOrderCommitmentChecker $commitments,
        private readonly ExitBatchResolver $exitBatches,
        private readonly CaravanBatchMover $mover,
        private readonly CaravanWeighingRecorder $weighingRecorder,
    ) {
    }

    /**
     * E2–E4 for animals joining a roll (issue or edit): alive, own, outside any terminal batch, not
     * pending in another open order, and not a bull working a service.
     *
     * @param list<int> $caravanIds
     * @return array<int, string> caravan id => label
     *
     * @throws ExitOrderDomainException with the errors of every animal, by position
     */
    public function checkRoll(array $caravanIds, int $companyId, ?string $ownCode, string $field = 'caravan_ids'): array
    {
        $errors = [];
        $labels = [];
        $committed = $this->commitments->committed($caravanIds, $companyId);
        $serviceBulls = $this->deathRepository->activeServiceBulls($caravanIds);

        foreach ($caravanIds as $index => $caravanId) {
            $caravan = $this->caravanRepository->findById($caravanId);
            $error = $caravan === null
                ? 'El animal no existe.'
                : $this->candidateError($caravan, $committed, $serviceBulls, $ownCode);

            if ($error !== null) {
                $errors["{$field}.{$index}"][] = $error;
                continue;
            }

            $labels[$caravanId] = $caravan->label();
        }

        if ($errors !== []) {
            throw ExitOrderDomainException::many($errors);
        }

        return $labels;
    }

    /**
     * Takes one load out of the order on that date. Rows are keyed "lines.{i}" in the errors.
     *
     * @param list<ExitLineDTO> $lines
     * @return array<int, array{snapshot: ExitSnapshot, weight_kg: ?float, price_per_kg: ?float, amount: ?float, movement_id: int, weight_id: ?int}>
     *
     * @throws ExitOrderDomainException
     */
    public function record(ExitOrderEntity $order, array $lines, string $exitDate, ?int $exitBatchId, string $observation): array
    {
        $companyId = $order->getCompanyId();
        $lineIds = array_map(fn (ExitLineDTO $line) => $line->caravanId, $lines);
        $pairs = $this->lineageRepository->nursingPairsOf($lineIds);
        $calvesWithMother = $this->calvesWithMother($lines, $pairs);

        $allIds = array_values(array_unique([...$lineIds, ...array_keys($calvesWithMother)]));
        $committed = $this->commitments->committed($allIds, $companyId);
        $serviceBulls = $this->deathRepository->activeServiceBulls($allIds);

        $errors = [];
        $caravans = [];
        $values = [];

        foreach ($lines as $index => $line) {
            $field = "lines.{$index}";
            $caravan = $this->caravanRepository->findById($line->caravanId);

            if ($caravan === null) {
                $errors["{$field}.caravan_id"][] = 'El animal no existe.';
                continue;
            }
            if ($order->pendingLine($line->caravanId) === null) {
                $errors["{$field}.caravan_id"][] = "{$caravan->label()} no está pendiente en esta orden.";
                continue;
            }

            $error = $this->candidateError($caravan, $committed, $serviceBulls, $order->getCode())
                ?? $this->calfDecisionError($caravan, $line, $pairs, $lineIds)
                ?? $this->amountsError($line);

            if ($error !== null) {
                $errors["{$field}.caravan_id"][] = $error;
                continue;
            }

            $caravans[$line->caravanId] = $caravan;
            $values[$line->caravanId] = $line;
        }

        foreach ($calvesWithMother as $calfId => $motherIndex) {
            $calf = $this->caravanRepository->findById($calfId);
            $error = $calf === null ? 'La cría no existe.' : $this->candidateError($calf, $committed, $serviceBulls, $order->getCode());

            if ($error !== null) {
                $errors["lines.{$motherIndex}.calf_decision"][] = "Cría al pie: {$error}";
                continue;
            }

            $caravans[$calfId] = $calf;
        }

        // Domain checks last, once every animal is known: the dates of each one.
        $snapshots = [];
        foreach ($caravans as $caravanId => $caravan) {
            $snapshots[$caravanId] = $this->snapshotOf($caravan);

            try {
                $caravan->registerExit($exitDate, $values[$caravanId]->weightKg ?? null);
            } catch (CaravanExitException $e) {
                $index = $this->indexOf($lines, $caravanId) ?? ($calvesWithMother[$caravanId] ?? 0);
                $errors["lines.{$index}.caravan_id"][] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw ExitOrderDomainException::many($errors);
        }

        $this->weanCalvesThatStay($lines, $pairs, $exitDate);

        // The domain changes first (gestation closed, calf no longer at foot): saving the entity
        // after the move would write its old batch back.
        foreach ($caravans as $caravan) {
            $this->caravanRepository->save($caravan);
            if ($caravan->getLineage() !== null) {
                $this->lineageRepository->save($caravan->getLineage());
            }
        }

        foreach (array_keys($calvesWithMother) as $calfId) {
            if (!$order->hasAnimal($calfId)) {
                $order->addCalfOfMother($calfId);
            }
        }

        $weightIds = $this->weigh($values, $exitDate, $order->getCode());
        $exitBatch = $this->exitBatches->resolve($exitBatchId);
        $movementIds = $this->mover->move($caravans, $exitBatch, new \DateTime($exitDate), 'SALE', $observation);

        $exits = [];
        foreach ($caravans as $caravanId => $caravan) {
            $line = $values[$caravanId] ?? null;
            $exits[$caravanId] = [
                'snapshot' => $snapshots[$caravanId],
                'weight_kg' => $line?->weightKg,
                'price_per_kg' => $line?->pricePerKg,
                'amount' => $line?->amount,
                'movement_id' => $movementIds[$caravanId],
                'weight_id' => $weightIds[$caravanId] ?? null,
            ];
        }

        return $exits;
    }

    /**
     * Why this animal cannot leave by an exit order, or null.
     *
     * @param array<int, string> $committed
     * @param array<int, string> $serviceBulls
     */
    private function candidateError(CaravanEntity $caravan, array $committed, array $serviceBulls, ?string $ownCode): ?string
    {
        $label = $caravan->label();
        $caravanId = (int) $caravan->getId();

        if ($caravan->isDead()) {
            return "{$label} está registrado como muerto: no sale por un egreso.";
        }

        $batch = $this->batch($caravan->getBatchId());
        if ($batch !== null && TerminalBatchTypes::contains($batch->getBatchTypeCode())) {
            return "{$label} ya no está en la hacienda (lote {$batch->getName()}).";
        }
        if ($batch !== null && $batch->getFarmId() !== null) {
            return "{$label} está en un lote externo o en tránsito: no es hacienda propia todavía.";
        }

        $order = $committed[$caravanId] ?? null;
        if ($order !== null && $order !== $ownCode) {
            return "{$label} está en la orden {$order}: resolvelo primero en esa orden.";
        }

        if (isset($serviceBulls[$caravanId])) {
            return "{$label} trabaja en el servicio {$serviceBulls[$caravanId]}: sale con «Reemplazar/retirar toro».";
        }

        return null;
    }

    /**
     * A cow with a calf at foot needs to say what happens to it (P1), unless the calf is in the
     * same load already.
     *
     * @param list<array{mother_id: int, calf_id: int}> $pairs
     * @param list<int> $lineIds
     */
    private function calfDecisionError(CaravanEntity $caravan, ExitLineDTO $line, array $pairs, array $lineIds): ?string
    {
        foreach ($pairs as $pair) {
            if ($pair['mother_id'] === $line->caravanId && !in_array($pair['calf_id'], $lineIds, true) && $line->calfDecision === null) {
                return "{$caravan->label()} tiene cría al pie: elegí si sale con ella o se queda.";
            }
        }

        return null;
    }

    private function amountsError(ExitLineDTO $line): ?string
    {
        if ($line->weightKg !== null && $line->weightKg <= 0) {
            return 'El peso tiene que ser mayor a 0 kg.';
        }
        if ($line->pricePerKg !== null && $line->pricePerKg <= 0) {
            return 'El precio por kg tiene que ser mayor a 0.';
        }
        if ($line->amount !== null && $line->amount < 0) {
            return 'El importe no puede ser negativo.';
        }

        return null;
    }

    /**
     * Calves at foot that leave with their mother and are not in the load themselves.
     *
     * @param list<ExitLineDTO> $lines
     * @param list<array{mother_id: int, calf_id: int}> $pairs
     * @return array<int, int> calf id => index of its mother's row
     */
    private function calvesWithMother(array $lines, array $pairs): array
    {
        $lineIds = array_map(fn (ExitLineDTO $line) => $line->caravanId, $lines);
        $calves = [];

        foreach ($lines as $index => $line) {
            if ($line->calfDecision !== CalfAtFootDecision::WITH_MOTHER) {
                continue;
            }
            foreach ($pairs as $pair) {
                if ($pair['mother_id'] === $line->caravanId && !in_array($pair['calf_id'], $lineIds, true)) {
                    $calves[$pair['calf_id']] = $index;
                }
            }
        }

        return $calves;
    }

    /**
     * "Se queda": the calf is weaned that day where it stands.
     *
     * @param list<ExitLineDTO> $lines
     * @param list<array{mother_id: int, calf_id: int}> $pairs
     */
    private function weanCalvesThatStay(array $lines, array $pairs, string $date): void
    {
        foreach ($lines as $line) {
            if ($line->calfDecision !== CalfAtFootDecision::STAYS) {
                continue;
            }
            foreach ($pairs as $pair) {
                if ($pair['mother_id'] === $line->caravanId) {
                    $this->lineageRepository->wean($pair['calf_id'], $date, null);
                }
            }
        }
    }

    /**
     * The chute weights of the load, as EXIT weighings (shrunk, outside the gain curve).
     *
     * @param array<int, ExitLineDTO> $values
     * @return array<int, int> caravan id => weight id
     */
    private function weigh(array $values, string $exitDate, string $code): array
    {
        $ids = [];
        foreach ($values as $caravanId => $line) {
            if ($line->weightKg === null) {
                continue;
            }

            try {
                $weight = $this->weighingRecorder->record($caravanId, WeighingReading::typical(
                    $line->weightKg,
                    new \DateTime($exitDate),
                    WeighingTypeCode::EXIT,
                    WeighingSource::EXIT_ORDER,
                    "Egreso {$code}"
                ));
            } catch (InvalidWeighingException $e) {
                throw ExitOrderDomainException::on('weight_kg', $e->getMessage());
            }

            $ids[$caravanId] = (int) $weight->getId();
        }

        return $ids;
    }

    private function snapshotOf(CaravanEntity $caravan): ExitSnapshot
    {
        $batch = $this->batch($caravan->getBatchId());

        return new ExitSnapshot(
            fromBatchId: $caravan->getBatchId(),
            activityId: $batch?->getActivityId(),
            categoryId: $caravan->getCategoryId(),
            wasPregnant: $caravan->getActiveGestation() !== null,
        );
    }

    private function batch(?int $batchId): ?BatchEntity
    {
        if ($batchId === null) {
            return null;
        }

        return $this->batches[$batchId] ??= $this->batchRepository->findById($batchId);
    }

    /** @param list<ExitLineDTO> $lines */
    private function indexOf(array $lines, int $caravanId): ?int
    {
        foreach ($lines as $index => $line) {
            if ($line->caravanId === $caravanId) {
                return $index;
            }
        }

        return null;
    }
}
