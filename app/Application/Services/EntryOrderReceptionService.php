<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\EntryOrders\ReceiveDTO;
use App\Core\Entities\EntryOrderAnimalEntity;
use App\Core\Entities\EntryOrderDteEntity;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Entities\EntryOrderReceiptSheetEntity;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\ReceptionMethod;
use App\Core\Enums\ReceiptSheetStatus;
use App\Core\Enums\ReceptionStatus;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Services\BatchWeightService;
use App\Models\Caravan;
use App\Models\CaravanBodyCondition;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;

/**
 * The animals of an entry order arrived. By hand it is done on one DTE, or on all of them at once
 * ("Recibir todo"); at the chute, on whatever caravans were read. Each received caravan comes into possession: it gets its
 * entry date (and weight and body condition, if they were taken), its PURCHASE movement, and its
 * row turns RECEIVED.
 * The ones declared missing will never arrive. The rest stay in transit. From an ING-03 sheet,
 * the sheet records which of its pages were received, and animals whose caravan no DTE lists are
 * reported as an incident instead of being received. A sheet weighed with one average records that
 * weight on each caravan as an average, not as a weighing of the animal.
 *
 * Everything is checked before anything is written, and every problem is reported at once, by row,
 * in the same shape as the DTE load. Must run inside the transaction that saves the order, and
 * `settleBatch()` must follow the save: the batch only counts what the stored rows say arrived.
 */
final class EntryOrderReceptionService
{
    public function __construct(private readonly BatchWeightService $batchWeightService)
    {
    }

    /**
     * @return array{warnings: list<array{code: string, message: string, row?: int}>, metadata: array<string, mixed>}
     *
     * @throws EntryDteValidationException
     * @throws EntryOrderDomainException
     */
    public function receive(EntryOrderEntity $order, ReceiveDTO $dto, ?int $userId): array
    {
        if (!$order->getStatus()->acceptsReception() || $order->inTransitCount() === 0) {
            throw EntryOrderDomainException::invalid(
                "La orden {$order->getCode()} está {$order->getStatus()->label()} y no tiene hacienda por recibir.",
                'ORDER_NOT_RECEIVING'
            );
        }

        ['received' => $received, 'missing' => $missing, 'unlisted' => $unlisted, 'warnings' => $warnings, 'dte' => $dte, 'sheet' => $sheet]
            = $this->validate($order, $dto);

        $lines = [];
        foreach ($received as $line) {
            $lines[] = [
                'caravan_id' => $line['animal']->getCaravanId(),
                'movement_id' => $this->enter($order, $line['animal'], $line['dte'], $dto->receivedAt, $line['weight'], $line['body_condition'], $sheet),
                'weight' => $line['weight'],
            ];
        }

        if ($lines !== [] || $missing !== []) {
            $order->receive($lines, $missing, $dto->receivedAt, $dto->method, $userId, $dto->reason);
        }

        $order->reportUnlistedCaravans($unlisted, $dto->receivedAt, $sheet?->label(), $userId);
        $sheet?->process($dto->pages !== [] ? $dto->pages : range(1, $sheet->getPageCount()));

        return [
            'warnings' => $warnings,
            'metadata' => [
                'reception' => true,
                'method' => $dto->method->value,
                'dte_number' => $dte?->getDteNumber(),
                'received_at' => $dto->receivedAt,
                'received' => count($lines),
                'missing' => count($missing),
                'reason' => $dto->reason,
                'in_transit' => $order->inTransitCount(),
                'unlisted' => count($unlisted),
                'receipt_sheet' => $sheet?->label(),
                'pages' => $sheet !== null ? $dto->pages : null,
            ],
        ];
    }

    /**
     * Once per reception, not per caravan: a single point in the batch's series for the arrival.
     * Called after the order is saved, so the received caravans already count as in possession.
     *
     * @param array<string, mixed> $metadata what receive() returned
     */
    public function settleBatch(EntryOrderEntity $order, array $metadata): void
    {
        if (($metadata['received'] ?? 0) > 0 && $order->getBatchId() !== null) {
            $this->batchWeightService->recalculateBatchWeight(
                $order->getBatchId(),
                BatchWeightCause::MOVEMENT_IN,
                new \DateTimeImmutable((string) $metadata['received_at'])
            );
        }
    }

    /**
     * @return array{
     *     received: list<array{animal: EntryOrderAnimalEntity, dte: EntryOrderDteEntity, weight: ?float, body_condition: ?float}>,
     *     missing: list<int>,
     *     unlisted: list<array{identification: string, sex: ?string, breed: ?string, coat: ?string, weight: ?float, body_condition: ?float}>,
     *     warnings: list<array{code: string, message: string, row?: int}>,
     *     dte: ?EntryOrderDteEntity,
     *     sheet: ?EntryOrderReceiptSheetEntity
     * }
     *
     * @throws EntryDteValidationException
     */
    private function validate(EntryOrderEntity $order, ReceiveDTO $dto): array
    {
        $troop = $order->getTroop();
        $header = [];
        $rows = [];
        $warnings = [];
        $dte = null;
        $sheet = null;
        $manual = $dto->method->declaresMissing();

        if ($dto->receivedAt > now()->toDateString()) {
            $header[] = $this->error('received_at', 'DATE_IN_FUTURE', 'La fecha de recepción no puede ser futura.');
        }

        if ($manual) {
            // Without a DTE it is "Recibir todo": caravans of any DTE of the order.
            $dte = $this->findDte($order, $dto);

            if ($dte === null && ($dto->dteId !== null || $dto->dteNumber !== null)) {
                $header[] = $this->error('dte_id', 'DTE_NOT_IN_ORDER', 'El DTE no pertenece a esta orden.');
            }
        } elseif ($dto->missing !== []) {
            $header[] = $this->error('missing', 'MISSING_NOT_AT_CHUTE', 'En manga sólo se reciben caravanas: las que no llegan se declaran desde la orden.');
        }

        if ($dto->receiptSheetId !== null) {
            $sheet = $order->findReceiptSheet($dto->receiptSheetId);

            if ($sheet->getStatus() === ReceiptSheetStatus::REPLACED) {
                $warnings[] = [
                    'code' => 'RECEIPT_SHEET_REPLACED',
                    'message' => "La hoja {$sheet->label()} había sido reemplazada por una más nueva. Se cargó igual: lo que dice el papel pasó.",
                ];
            }
        }

        if ($dto->received === [] && $dto->missing === [] && $dto->unlisted === []) {
            $header[] = $this->error('received', 'NOTHING_TO_RECEIVE', 'La recepción no trae ninguna caravana.');
        }

        if ($manual && $dto->missing !== [] && $dto->reason === null) {
            $header[] = $this->error('reason', 'REASON_REQUIRED', 'Indicá por qué no van a llegar.');
        }

        $byIdentification = [];
        foreach ($order->getDtes() as $loaded) {
            foreach ($loaded->getAnimals() as $animal) {
                $byIdentification[mb_strtoupper($animal->getIdentification())] = $animal;
            }
        }

        $seen = [];
        $received = [];
        $beforeDte = false;
        // An average is one weight for many lines: out of range, it is said once.
        $average = $sheet?->getWeighingMode() === WeighingMode::AVERAGE;
        $warnedWeights = [];

        foreach ($dto->received as $i => $row) {
            $animal = $row['caravan_id'] !== null
                ? $order->findAnimal($row['caravan_id'])
                : ($row['identification'] !== null ? ($byIdentification[mb_strtoupper($row['identification'])] ?? null) : null);
            $label = $row['identification'] ?? (string) $row['caravan_id'];
            $error = $this->rowProblem($order, $animal, $dte, $label, $seen);

            if ($error !== null) {
                $rows[] = $this->rowError($i, 'received', $error[0], $error[1]);
                continue;
            }

            $owner = $order->dteOf($animal->getCaravanId());
            $seen[$animal->getCaravanId()] = true;

            if ($dto->receivedAt < $owner->getDteDate()) {
                $beforeDte = true;
            }

            $weight = $row['weight'];
            if ($weight !== null && $weight <= 0) {
                $rows[] = $this->rowError($i, 'weight', 'WEIGHT_INVALID', 'El peso tiene que ser mayor que cero.');
                continue;
            }

            $bodyCondition = $row['body_condition'] ?? null;
            if ($bodyCondition !== null && !self::isBodyCondition($bodyCondition)) {
                $rows[] = $this->rowError($i, 'body_condition', 'BODY_CONDITION_INVALID', self::bodyConditionMessage($bodyCondition));
                continue;
            }

            if ($weight !== null && (($troop->minWeight !== null && $weight < $troop->minWeight) || ($troop->maxWeight !== null && $weight > $troop->maxWeight))
                && !($average && isset($warnedWeights[(string) $weight]))) {
                $warnedWeights[(string) $weight] = true;
                $warnings[] = [
                    ...($average ? [] : ['row' => $i]),
                    'code' => 'WEIGHT_OUT_OF_RANGE',
                    'message' => ($average ? "El peso promedio de {$weight} kg" : "{$weight} kg") . ' está fuera del rango declarado en la compra ('
                        . ($troop->minWeight ?? '—') . ' a ' . ($troop->maxWeight ?? '—') . ' kg). Revisá la lectura.',
                ];
            }

            $received[] = ['animal' => $animal, 'dte' => $owner, 'weight' => $weight, 'body_condition' => $bodyCondition];
        }

        $missing = [];
        if ($manual) {
            foreach ($dto->missing as $i => $caravanId) {
                $animal = $order->findAnimal($caravanId);
                $error = $this->rowProblem($order, $animal, $dte, (string) $caravanId, $seen);

                if ($error !== null) {
                    $rows[] = $this->rowError($i, 'missing', $error[0], $error[1]);
                    continue;
                }

                $seen[$caravanId] = true;
                $missing[] = $caravanId;
            }
        }

        $unlisted = [];
        $known = [];
        foreach ($dto->unlisted as $i => $line) {
            $tag = mb_strtoupper($line['identification']);

            if (isset($byIdentification[$tag])) {
                $rows[] = $this->rowError($i, 'unlisted', 'CARAVAN_IN_ORDER', "La caravana {$line['identification']} está en un DTE de la orden: se recibe desde ese DTE.");
                continue;
            }

            if (isset($known[$tag])) {
                $rows[] = $this->rowError($i, 'unlisted', 'CARAVAN_DUPLICATED', "La caravana {$line['identification']} está repetida en la recepción.");
                continue;
            }

            if (($line['body_condition'] ?? null) !== null && !self::isBodyCondition($line['body_condition'])) {
                $rows[] = $this->rowError($i, 'unlisted', 'BODY_CONDITION_INVALID', self::bodyConditionMessage($line['body_condition']));
                continue;
            }

            $existing = Caravan::withoutGlobalScopes()->where('identification', $line['identification'])->first();

            if ($existing !== null) {
                $rows[] = $this->rowError($i, 'unlisted', 'CARAVAN_EXISTS', "La caravana {$line['identification']} ya existe en el sistema: revisá la lectura.");
                continue;
            }

            $known[$tag] = true;
            $unlisted[] = $line;
        }

        if ($beforeDte) {
            $header[] = $this->error('received_at', 'RECEIVED_BEFORE_DTE', 'La hacienda no pudo llegar antes de que se emitiera su DTE.');
        }

        if ($header !== [] || $rows !== []) {
            throw new EntryDteValidationException('La recepción tiene datos para corregir.', $header, $rows);
        }

        return ['received' => $received, 'missing' => $missing, 'unlisted' => $unlisted, 'warnings' => $warnings, 'dte' => $dte, 'sheet' => $sheet];
    }

    /**
     * Why a caravan cannot be received (or declared missing) in this reception, or null.
     *
     * @param array<int, true> $seen
     * @return array{0: string, 1: string}|null
     */
    private function rowProblem(EntryOrderEntity $order, ?EntryOrderAnimalEntity $animal, ?EntryOrderDteEntity $dte, string $label, array $seen): ?array
    {
        if ($animal === null) {
            return ['CARAVAN_NOT_IN_ORDER', "La caravana {$label} no está en la orden {$order->getCode()}."];
        }

        if ($dte !== null && $dte->findAnimal($animal->getCaravanId()) === null) {
            return ['CARAVAN_NOT_IN_ORDER', "La caravana {$animal->getIdentification()} no está en el DTE {$dte->getDteNumber()}."];
        }

        if (isset($seen[$animal->getCaravanId()])) {
            return ['CARAVAN_DUPLICATED', "La caravana {$animal->getIdentification()} está repetida en la recepción."];
        }

        return match ($animal->getReceptionStatus()) {
            ReceptionStatus::RECEIVED => ['CARAVAN_ALREADY_RECEIVED', "La caravana {$animal->getIdentification()} ya fue recibida el {$animal->getReceivedAt()}."],
            ReceptionStatus::MISSING => ['CARAVAN_MARKED_MISSING', "La caravana {$animal->getIdentification()} se declaró como que no llegará."],
            ReceptionStatus::PENDING => null,
        };
    }

    private function findDte(EntryOrderEntity $order, ReceiveDTO $dto): ?EntryOrderDteEntity
    {
        foreach ($order->getDtes() as $dte) {
            if (($dto->dteId !== null && $dte->getId() === $dto->dteId)
                || ($dto->dteNumber !== null && strcasecmp($dte->getDteNumber(), $dto->dteNumber) === 0)) {
                return $dte;
            }
        }

        return null;
    }

    /**
     * The official body condition scale: 1 to 5, in steps of 0.5.
     */
    public static function isBodyCondition(float $score): bool
    {
        return $score >= 1 && $score <= 5 && fmod($score * 2, 1.0) === 0.0;
    }

    private static function bodyConditionMessage(float $score): string
    {
        return "El estado corporal {$score} no está en la escala oficial: de 1 a 5, de 0,5 en 0,5 (1 · 1,5 · 2 … 5).";
    }

    /**
     * The caravan is in the field from today: entry date, weight and body condition if taken, and
     * its PURCHASE movement. The weight of a sheet weighed with one average is recorded as such.
     */
    private function enter(
        EntryOrderEntity $order,
        EntryOrderAnimalEntity $animal,
        EntryOrderDteEntity $dte,
        string $receivedAt,
        ?float $weight,
        ?float $bodyCondition,
        ?EntryOrderReceiptSheetEntity $sheet
    ): int {
        $caravan = Caravan::withoutGlobalScopes()->findOrFail($animal->getCaravanId());

        $caravan->entry_date = $receivedAt;
        if ($weight !== null) {
            $caravan->entry_weight = $weight;
        }
        $caravan->save();

        $where = $sheet !== null
            ? "{$order->getCode()}, DTE {$dte->getDteNumber()}, hoja {$sheet->label()}"
            : "{$order->getCode()}, DTE {$dte->getDteNumber()}";

        if ($weight !== null) {
            $average = $sheet?->getWeighingMode() === WeighingMode::AVERAGE;

            CaravanWeight::where('caravan_id', $caravan->id)->update(['current' => false]);
            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $weight,
                'current' => true,
                'weighing_date' => $receivedAt,
                'method' => $average ? WeighingMode::AVERAGE->value : WeighingMode::INDIVIDUAL->value,
                'notes' => ($average ? 'Peso promedio de ingreso' : 'Pesaje de ingreso') . " ({$where})",
            ]);
        }

        if ($bodyCondition !== null) {
            CaravanBodyCondition::where('caravan_id', $caravan->id)->update(['current' => false]);
            CaravanBodyCondition::create([
                'caravan_id' => $caravan->id,
                'score' => $bodyCondition,
                'current' => true,
                'assessed_at' => $receivedAt,
                'source' => CaravanBodyCondition::SOURCE_ENTRY_RECEPTION,
                'entry_order_receipt_sheet_id' => $sheet?->getId(),
                'notes' => "Estado corporal de ingreso ({$where})",
            ]);
        }

        $movement = CaravanMovement::create([
            'caravan_id' => $caravan->id,
            'company_id' => $order->getCompanyId(),
            'to_batch_id' => $caravan->batch_id,
            'provider_id' => $caravan->provider_id,
            'renspa' => $caravan->renspa,
            'from_renspa' => $caravan->renspa,
            'type' => 'PURCHASE',
            'movement_date' => $receivedAt,
            'provenance_metadata' => $caravan->provenance_metadata,
            'observations' => "Ingreso por DTE {$dte->getDteNumber()} de la orden {$order->getCode()}",
        ]);

        return (int) $movement->id;
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function error(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }

    /**
     * @return array{row: int, field: string, code: string, message: string}
     */
    private function rowError(int $row, string $field, string $code, string $message): array
    {
        return ['row' => $row, 'field' => $field, 'code' => $code, 'message' => $message];
    }
}
