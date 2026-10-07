<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\EntryOrders\ReceiveDTO;
use App\Core\Entities\EntryOrderAnimalEntity;
use App\Core\Entities\EntryOrderBreedEntity;
use App\Core\Entities\EntryOrderCategoryEntity;
use App\Core\Entities\EntryOrderDteEntity;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Entities\EntryOrderReceiptSheetEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\EntryOrderIncidentType;
use App\Core\Enums\ReceiptSheetStatus;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IBreedRepository;
use App\Core\Services\BatchWeightService;
use App\Core\Services\TroopLineResolver;
use App\Core\ValueObjects\CaravanProvenance;
use App\Core\ValueObjects\EntryTroop;
use App\Core\ValueObjects\TroopLineMatch;
use App\Models\Caravan;
use App\Models\CaravanBodyCondition;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\Farm;

/**
 * The animals of a DTE of an entry order arrived. Each line is a caravan written down at the
 * chute — on an ING-03 sheet or by hand — and receiving it creates it: in the order's batch, with
 * the order's provenance, its entry date (and weight and body condition, if taken) and its PURCHASE
 * movement. Sex and breed are the order's unless the order has both sexes or several breeds, and
 * then each line says which. A manual reception confirms the head that arrived and closes the DTE;
 * its caravans are optional and can be written later, identifying head already received. The category is the only one of the order the animal's sex admits;
 * when its sex admits several, the line says which (CAT, the category's number). Head of the DTE left out stay in transit, unless they are declared as
 * never arriving. More animals than the DTE declares are received anyway and raised as an incident.
 * A sheet printed with words brings breed, coat and category as written: TroopLineResolver turns
 * them into the order's lines, and a breed of the catalog the order does not declare is received
 * with that breed and raised as an incident. What the chute saw on an animal as it came off the
 * truck (an injured eye, ear or limb) is kept on its reception line.
 * A sheet weighed with one average records that weight on each caravan as an average, not as a
 * weighing of the animal.
 *
 * Everything is checked before anything is written, and every problem is reported at once, by row.
 * Must run inside the transaction that saves the order, and `settleBatch()` must follow the save.
 */
final class EntryOrderReceptionService
{
    public function __construct(
        private readonly BatchWeightService $batchWeightService,
        private readonly IBreedRepository $breedRepository
    ) {
    }

    /**
     * @return array{warnings: list<array{code: string, message: string, row?: int}>, metadata: array<string, mixed>}
     *
     * @throws EntryDteValidationException
     * @throws EntryOrderDomainException
     */
    public function receive(EntryOrderEntity $order, ReceiveDTO $dto, ?int $userId): array
    {
        ['dte' => $dte, 'sheet' => $sheet, 'warnings' => $warnings, 'rows' => $rows] = $this->validate($order, $dto);

        $origin = $this->origin($order, $dte);
        $animals = [];
        $otherBreed = [];
        foreach ($rows as $row) {
            $animals[] = $this->enter($order, $dte, $row, $origin, $dto, $userId, $sheet);

            if ($row['other_breed'] !== null) {
                $otherBreed[$row['caravana']] = $row['other_breed']['label'];
            }
        }

        $incidents = $order->receive($dte, $animals, $dto->missingHeadCount, $dto->reason, $userId, $sheet?->label(), $dto->receivedHeadCount);
        $sheet?->process($dto->pages !== [] ? $dto->pages : range(1, $sheet->getPageCount()));

        if ($otherBreed !== []) {
            $incidents[] = $order->reportBreedMismatch($dte, $otherBreed, $sheet?->label(), $userId);
        }

        foreach ($incidents as $incident) {
            // Missing head are what the reviewer declared; another breed was already said line by line.
            if (!in_array($incident->getType(), [EntryOrderIncidentType::MISSING_HEAD, EntryOrderIncidentType::BREED_MISMATCH], true)) {
                $warnings[] = [
                    'code' => $incident->getType()->value,
                    'message' => $incident->getDetail() . ' Se registró una novedad para revisar con el proveedor.',
                ];
            }
        }

        return [
            'warnings' => $warnings,
            'metadata' => [
                'reception' => true,
                'method' => $dto->method->value,
                'dte_number' => $dte->getDteNumber(),
                'received_at' => $dto->receivedAt,
                'received' => $dto->receivedHeadCount ?? count($animals),
                'received_head_count' => $dto->receivedHeadCount,
                'caravans' => count($animals),
                'missing' => $dto->missingHeadCount,
                'reason' => $dto->reason,
                'in_transit' => $order->inTransitCount(),
                'incidents' => array_map(fn ($i) => $i->getType()->value, $incidents),
                'receipt_sheet' => $sheet?->label(),
                'tri_number' => $dto->triNumber,
                'pages' => $sheet !== null ? $dto->pages : null,
                'arrival_findings' => self::findingCounts($animals),
            ],
        ];
    }

    /**
     * Once per reception, not per caravan: a single point in the batch's series for the arrival.
     * Called after the order is saved, so the received caravans already count.
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
     * Every line checked, and its written breed, coat and category turned into the order's lines.
     *
     * @return array{dte: EntryOrderDteEntity, sheet: ?EntryOrderReceiptSheetEntity, warnings: list<array{code: string, message: string, row?: int}>, rows: list<array<string, mixed>>}
     *
     * @throws EntryDteValidationException
     */
    private function validate(EntryOrderEntity $order, ReceiveDTO $dto): array
    {
        $troop = $order->getTroop();
        $header = [];
        $rows = [];
        $warnings = [];

        $sheet = $dto->receiptSheetId !== null ? $order->findReceiptSheet($dto->receiptSheetId) : null;
        $dte = $this->findDte($order, $dto, $sheet);

        if ($dte === null) {
            throw new EntryDteValidationException('La recepción tiene datos para corregir.', [
                $this->error('dte_id', 'DTE_NOT_IN_ORDER', 'El DTE no pertenece a esta orden.'),
            ], []);
        }

        if ($sheet !== null && $sheet->getDteId() !== $dte->getId()) {
            $header[] = $this->error('receipt_sheet_id', 'SHEET_NOT_OF_DTE', "La hoja {$sheet->label()} es del DTE {$sheet->getDteNumber()}, no del {$dte->getDteNumber()}.");
        }

        if ($sheet?->getStatus() === ReceiptSheetStatus::REPLACED) {
            $warnings[] = [
                'code' => 'RECEIPT_SHEET_REPLACED',
                'message' => "La hoja {$sheet->label()} había sido reemplazada por una más nueva. Se cargó igual: lo que dice el papel pasó.",
            ];
        }

        $byHead = $dto->receivedHeadCount !== null;
        $uncaravaned = $dte->getUncaravanedHeadCount();
        $open = $order->getStatus()->acceptsReception();

        if (!$open && ($byHead || $uncaravaned === 0)) {
            $header[] = $this->error('dte_id', 'ORDER_NOT_RECEIVING', "La orden {$order->getCode()} está {$order->getStatus()->label()} y no tiene hacienda por recibir.");
        } elseif (!$open && count($dto->animals) > $uncaravaned) {
            $header[] = $this->error('animals', 'CARAVANS_EXCEED_UNCARAVANED', "La orden está {$order->getStatus()->label()}: sólo se pueden cargar las caravanas de las {$uncaravaned} cabezas recibidas sin caravana.");
        } elseif ($byHead ? $dte->pendingCount() === 0 : $dte->toIdentifyCount() === 0) {
            $header[] = $this->error('dte_id', 'DTE_NOTHING_PENDING', "El DTE {$dte->getDteNumber()} no tiene cabezas en tránsito.");
        }

        if ($byHead && count($dto->animals) > $uncaravaned + $dto->receivedHeadCount) {
            $header[] = $this->error(
                'received_head_count',
                'CARAVANS_EXCEED_HEADS',
                'Se cargaron ' . count($dto->animals) . " caravanas para {$dto->receivedHeadCount} cabezas recibidas: no puede haber más caravanas que cabezas."
            );
        }

        if ($dto->receivedAt > now()->toDateString()) {
            $header[] = $this->error('received_at', 'DATE_IN_FUTURE', 'La fecha de recepción no puede ser futura.');
        } elseif ($dto->receivedAt < $dte->getDteDate()) {
            $header[] = $this->error('received_at', 'RECEIVED_BEFORE_DTE', 'La hacienda no pudo llegar antes de que se emitiera su DTE.');
        }

        if ($dto->animals === [] && $dto->missingHeadCount === 0 && !$byHead) {
            $header[] = $this->error('animals', 'NOTHING_TO_RECEIVE', 'La recepción no trae ninguna caravana.');
        }

        if (!$byHead && $dto->missingHeadCount > 0) {
            $left = max(0, $dte->pendingCount() - max(0, count($dto->animals) - $uncaravaned));

            if ($dto->reason === null) {
                $header[] = $this->error('reason', 'REASON_REQUIRED', 'Indicá por qué no van a llegar.');
            }

            if ($dto->missingHeadCount > $left) {
                $header[] = $this->error('missing_head_count', 'MISSING_EXCEEDS_PENDING', "Después de esta recepción quedan {$left} cabezas en tránsito: no pueden faltar {$dto->missingHeadCount}.");
            }
        }

        $inherited = $troop->sexComposition?->inheritedSex();
        $breeds = $troop->breedsByPosition();
        $existing = Caravan::withoutGlobalScopes()
            ->whereIn('identification', array_values(array_filter(array_column($dto->animals, 'caravana'))))
            ->pluck('identification')
            ->map(fn ($id) => mb_strtoupper((string) $id))
            ->flip();
        $seen = [];
        // An average is one weight for many lines: out of range, it is said once.
        $average = $sheet?->getWeighingMode() === WeighingMode::AVERAGE;
        $warnedWeights = [];
        $resolver = $this->resolverFor($dto);
        $resolved = [];

        foreach ($dto->animals as $i => $row) {
            $row['other_breed'] = null;
            $tag = $row['caravana'];
            $upper = mb_strtoupper($tag);

            if ($tag === '') {
                $rows[] = $this->rowError($i, 'caravana', 'CARAVAN_MISSING', 'Falta el número de caravana.');
            } elseif (isset($seen[$upper])) {
                $rows[] = $this->rowError($i, 'caravana', 'CARAVAN_DUPLICATED', "La caravana {$tag} está repetida en la recepción (fila " . ($seen[$upper] + 1) . ').');
            } elseif (isset($existing[$upper])) {
                $rows[] = $this->rowError($i, 'caravana', 'CARAVAN_EXISTS', "La caravana {$tag} ya existe en el sistema: revisá la lectura.");
            }

            if ($tag !== '') {
                $seen[$upper] ??= $i;
            }

            if ($inherited === null) {
                if ($row['sex'] === null) {
                    $rows[] = $this->rowError($i, 'sex', 'SEX_MISSING', 'La tropa es de ambos sexos: indicá si es macho (M) o hembra (H).');
                } elseif (AnimalSex::tryFrom($row['sex']) === null) {
                    $rows[] = $this->rowError($i, 'sex', 'SEX_INVALID', "'{$row['sex']}' no es un sexo: M o H.");
                }
            } elseif ($row['sex'] !== null && $row['sex'] !== $inherited->value) {
                $rows[] = $this->rowError($i, 'sex', 'SEX_CONTRADICTS_ORDER', "La orden es de {$troop->sexComposition->label()}.");
            }

            $sex = $inherited ?? AnimalSex::tryFrom((string) $row['sex']);
            $categoryWritten = $row['category_text'] !== null && $troop->categoriesByPosition() !== [];

            if ($categoryWritten && $row['category_position'] !== null) {
                $rows[] = $this->rowError($i, 'category_text', 'REFERENCE_CONFLICT', 'El renglón trae la categoría dos veces: por número y escrita. Dejá una.');
            } elseif ($categoryWritten) {
                $match = $resolver->categoryLine($troop, $row['category_text'], $sex);
                $row['category_position'] = $match->isMatched() ? $match->position : null;

                if ($match->isError()) {
                    $rows[] = $this->matchError($i, $match);
                }
            }

            if (!$categoryWritten || $row['category_position'] !== null) {
                foreach ($this->categoryErrors($troop, $row['category_position'], $sex) as [$code, $message]) {
                    $rows[] = $this->rowError($i, $categoryWritten ? 'category_text' : 'category_position', $code, $message);
                }
            }

            $breedWritten = $row['breed_text'] !== null || $row['color_text'] !== null;

            if ($breedWritten && $row['breed_position'] !== null) {
                $rows[] = $this->rowError($i, 'breed_text', 'REFERENCE_CONFLICT', 'El renglón trae la raza dos veces: por letra y escrita. Dejá una.');
            } elseif ($breedWritten) {
                $match = $resolver->breedLine($troop, (string) $row['breed_text'], $row['color_text']);

                if ($match->isMatched()) {
                    $row['breed_position'] = $match->position;
                } elseif ($match->isOutsideOrder()) {
                    $row['other_breed'] = ['breed_id' => $match->breedId, 'color_id' => $match->colorId, 'label' => (string) $match->label];
                    $warnings[] = [
                        'row' => $i,
                        'code' => 'BREED_NOT_IN_ORDER',
                        'message' => "La caravana {$tag} es {$match->label}, una raza que la compra no declara: se recibe así y queda una novedad.",
                    ];
                } else {
                    $rows[] = $this->matchError($i, $match);
                }
            } elseif ($row['breed_position'] !== null && !isset($breeds[$row['breed_position']])) {
                $rows[] = $this->rowError($i, 'breed_position', 'BREED_UNKNOWN', 'La orden no declara esa raza.');
            } elseif ($row['breed_position'] === null && $troop->hasSeveralBreeds()) {
                $warnings[] = ['row' => $i, 'code' => 'BREED_UNDECLARED', 'message' => "La caravana {$tag} queda sin raza declarada."];
            }

            $weight = $row['weight'];
            if ($weight !== null && $weight <= 0) {
                $rows[] = $this->rowError($i, 'weight', 'WEIGHT_INVALID', 'El peso tiene que ser mayor que cero.');
            } elseif ($weight !== null && (($troop->minWeight !== null && $weight < $troop->minWeight) || ($troop->maxWeight !== null && $weight > $troop->maxWeight))
                && !($average && isset($warnedWeights[(string) $weight]))) {
                $warnedWeights[(string) $weight] = true;
                $warnings[] = [
                    ...($average ? [] : ['row' => $i]),
                    'code' => 'WEIGHT_OUT_OF_RANGE',
                    'message' => ($average ? "El peso promedio de {$weight} kg" : "{$weight} kg") . ' está fuera del rango declarado en la compra ('
                        . ($troop->minWeight ?? '—') . ' a ' . ($troop->maxWeight ?? '—') . ' kg). Revisá la lectura.',
                ];
            }

            if ($row['body_condition'] !== null && !self::isBodyCondition($row['body_condition'])) {
                $rows[] = $this->rowError($i, 'body_condition', 'BODY_CONDITION_INVALID', self::bodyConditionMessage($row['body_condition']));
            }

            $resolved[] = $row;
        }

        if ($header !== [] || $rows !== []) {
            throw new EntryDteValidationException('La recepción tiene datos para corregir.', $header, $rows);
        }

        return ['dte' => $dte, 'sheet' => $sheet, 'warnings' => $warnings, 'rows' => $resolved];
    }

    /**
     * The resolver of written breeds and categories. The catalog is only read when some line
     * wrote a breed: a breed outside the order is looked up there.
     */
    private function resolverFor(ReceiveDTO $dto): TroopLineResolver
    {
        foreach ($dto->animals as $row) {
            if ($row['breed_text'] !== null || $row['color_text'] !== null) {
                return new TroopLineResolver($this->breedRepository->getAll());
            }
        }

        return new TroopLineResolver();
    }

    /**
     * A written cell that does not name one line: the reason, and what it could say instead.
     *
     * @return array{row: int, field: string, code: string, message: string, candidates: list<string>}
     */
    private function matchError(int $row, TroopLineMatch $match): array
    {
        return [...$this->rowError($row, $match->field, (string) $match->code, (string) $match->message), 'candidates' => $match->candidates];
    }

    /**
     * How many caravans of the reception came with each finding.
     *
     * @param EntryOrderAnimalEntity[] $animals
     * @return array<string, int>
     */
    private static function findingCounts(array $animals): array
    {
        $counts = [];

        foreach ($animals as $animal) {
            foreach ($animal->getArrivalFindings() as $finding) {
                $counts[$finding->value] = ($counts[$finding->value] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * What is wrong with the category of a line: a number the order does not declare, one its sex
     * does not admit, or none when its sex admits several. Nothing to say while its sex is unknown.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function categoryErrors(EntryTroop $troop, ?int $position, ?AnimalSex $sex): array
    {
        $lines = $troop->categoriesByPosition();

        if ($lines === []) {
            return [];
        }

        if ($position !== null) {
            $line = $lines[$position] ?? null;

            if ($line === null) {
                return [['CATEGORY_UNKNOWN', "La orden no declara la categoría {$position}."]];
            }

            return $sex !== null && !$line->admits($sex)
                ? [['CATEGORY_CONTRADICTS_SEX', "La categoría {$line->getCategoryName()} no admite " . self::sexWord($sex) . '.']]
                : [];
        }

        if ($sex === null) {
            return [];
        }

        $candidates = $troop->categoriesAdmitting($sex);

        return match (count($candidates)) {
            1 => [],
            0 => [['CATEGORY_NONE_FOR_SEX', 'Ninguna categoría de la orden admite ' . self::sexWord($sex) . '.']],
            default => [['CATEGORY_MISSING', 'Indicá la categoría: ' . implode(', ', array_map(
                fn (EntryOrderCategoryEntity $c) => "{$c->getPosition()} {$c->getCategoryName()}",
                $candidates
            )) . '.']],
        };
    }

    /**
     * The category line of a valid line: the one it declares, or the only one its sex admits.
     */
    private static function categoryOf(EntryTroop $troop, ?int $position, AnimalSex $sex): ?EntryOrderCategoryEntity
    {
        if ($position !== null) {
            return $troop->categoriesByPosition()[$position] ?? null;
        }

        $candidates = $troop->categoriesAdmitting($sex);

        return count($candidates) === 1 ? reset($candidates) : null;
    }

    private static function sexWord(AnimalSex $sex): string
    {
        return $sex === AnimalSex::MALE ? 'machos' : 'hembras';
    }

    private function findDte(EntryOrderEntity $order, ReceiveDTO $dto, ?EntryOrderReceiptSheetEntity $sheet): ?EntryOrderDteEntity
    {
        $id = $dto->dteId ?? $sheet?->getDteId();

        foreach ($order->getDtes() as $dte) {
            if ($id !== null && $dte->getId() === $id) {
                return $dte;
            }
        }

        return $dto->dteNumber !== null ? $order->findDteByNumber($dto->dteNumber) : null;
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
     * What every caravan of the reception takes from the order: where it comes from and which breed
     * line it has when the order declares only one.
     *
     * @return array{renspa: string, provenance: array<string, mixed>, single_breed: ?EntryOrderBreedEntity}
     */
    private function origin(EntryOrderEntity $order, EntryOrderDteEntity $dte): array
    {
        $troop = $order->getTroop();
        $breeds = $troop->breedsByPosition();
        $renspa = (string) (Farm::withoutGlobalScopes()->whereKey($troop->farmId)->value('renspa') ?? 'NO_DEFINIDO');

        return [
            'renspa' => $renspa,
            'provenance' => (new CaravanProvenance(
                originRenspa: $renspa,
                originProviderId: $troop->providerId,
                dteNumber: $dte->getDteNumber(),
                auctionName: $troop->auctionNumber,
                extraData: ['entry_order_code' => $order->getCode(), 'source' => 'ENTRY_ORDER']
            ))->toArray(),
            'single_breed' => count($breeds) === 1 ? reset($breeds) : null,
        ];
    }

    /**
     * The animal is in the field from today: its caravan is created in the order's batch with its
     * entry date, weight and body condition if taken, and its PURCHASE movement. The weight of a
     * sheet weighed with one average is recorded as such.
     *
     * A breed outside the order is the catalog's breed and coat written on the line.
     *
     * @param array<string, mixed> $row a validated line, its breed and category resolved
     * @param array{renspa: string, provenance: array<string, mixed>, single_breed: ?EntryOrderBreedEntity} $origin
     */
    private function enter(
        EntryOrderEntity $order,
        EntryOrderDteEntity $dte,
        array $row,
        array $origin,
        ReceiveDTO $dto,
        ?int $userId,
        ?EntryOrderReceiptSheetEntity $sheet
    ): EntryOrderAnimalEntity {
        $troop = $order->getTroop();
        $sex = $troop->sexComposition?->inheritedSex()?->value ?? (string) $row['sex'];
        $otherBreed = $row['other_breed'];
        $breedLine = $otherBreed !== null ? null : ($row['breed_position'] !== null ? $troop->breedsByPosition()[$row['breed_position']] : $origin['single_breed']);
        $categoryLine = self::categoryOf($troop, $row['category_position'], AnimalSex::from($sex));
        $weight = $row['weight'];
        $receivedAt = $dto->receivedAt;

        $caravan = Caravan::create([
            'company_id' => $order->getCompanyId(),
            'batch_id' => (int) $order->getBatchId(),
            'provider_id' => $troop->providerId,
            'renspa' => $origin['renspa'],
            'identification' => $row['caravana'],
            'category_id' => $categoryLine?->getCategoryId(),
            'subcategory_id' => null,
            'sex' => $sex,
            'teeth' => 0,
            'breed_id' => $otherBreed['breed_id'] ?? $breedLine?->getBreedId(),
            'color_id' => $otherBreed !== null ? $otherBreed['color_id'] : $breedLine?->getColorId(),
            'entry_weight' => $weight,
            'entry_date' => $receivedAt,
            'provenance_metadata' => $origin['provenance'],
        ]);

        $where = $sheet !== null
            ? "{$order->getCode()}, DTE {$dte->getDteNumber()}, hoja {$sheet->label()}"
            : "{$order->getCode()}, DTE {$dte->getDteNumber()}";

        if ($weight !== null) {
            $average = $sheet?->getWeighingMode() === WeighingMode::AVERAGE;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $weight,
                'current' => true,
                'weighing_date' => $receivedAt,
                'method' => $average ? WeighingMode::AVERAGE->value : WeighingMode::INDIVIDUAL->value,
                'notes' => ($average ? 'Peso promedio de ingreso' : 'Pesaje de ingreso') . " ({$where})",
            ]);
        }

        if ($row['body_condition'] !== null) {
            CaravanBodyCondition::create([
                'caravan_id' => $caravan->id,
                'score' => $row['body_condition'],
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

        return new EntryOrderAnimalEntity(
            id: null,
            caravanId: (int) $caravan->id,
            identification: $row['caravana'],
            sex: $sex,
            breedPosition: $breedLine?->getPosition(),
            caravanMovementId: (int) $movement->id,
            entryWeight: $weight,
            receivedAt: $receivedAt,
            receptionMethod: $dto->method,
            receivedByUserId: $userId,
            categoryPosition: $categoryLine?->getPosition(),
            arrivalFindings: $row['arrival_findings'],
            receiptSheetId: $sheet?->getId()
        );
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
