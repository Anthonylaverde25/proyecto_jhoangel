<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchNameMode;
use App\Core\Enums\EntryOrderIncidentType;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\SexComposition;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\ReferenceMode;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\ValueObjects\EntryTroop;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The purchase of an external troop. It is born without caravans. Each official transit document
 * (DTE) declares how many head are on their way, and the caravans only exist once each animal is
 * received — written down on an ING-03 sheet at the chute or by hand.
 *
 * Once confirmed the status is never set by hand: it tells what the order is waiting for —
 * documents (AWAITING_DTE) or animals (IN_TRANSIT) — and follows the DTEs and receptions.
 * Whatever does not match the purchase is raised as an incident and never blocks the order.
 */
final class EntryOrderEntity
{
    private bool $troopReplaced = false;

    /**
     * @param EntryOrderDteEntity[] $dtes
     * @param EntryOrderIncidentEntity[] $incidents
     * @param TransferOrderHistoryEntity[] $history the same history line as the other orders
     * @param array<string, ?string> $names display names of what the ids point to
     * @param EntryOrderReceiptSheetEntity[] $receiptSheets the ING-03 sheets issued for its DTEs
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly string $code,
        private readonly int $number,
        private EntryOrderStatus $status,
        private readonly TransferOrderKind $kind,
        private EntryTroop $troop,
        private ?string $batchName,
        private BatchNameMode $batchNameMode,
        private ?int $batchId,
        private readonly ?int $requestedByUserId,
        private ?DateTimeInterface $confirmedAt = null,
        private ?DateTimeInterface $printedAt = null,
        private ?DateTimeInterface $firstDteAt = null,
        private ?DateTimeInterface $closedAt = null,
        private ?string $closingReason = null,
        private array $dtes = [],
        private array $incidents = [],
        private readonly array $history = [],
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly array $names = [],
        private array $receiptSheets = []
    ) {
    }

    /**
     * A new order, always a draft: confirming it needs its batch, which the factory creates.
     *
     * @throws EntryOrderDomainException
     */
    public static function draft(
        int $companyId,
        string $code,
        int $number,
        TransferOrderKind $kind,
        EntryTroop $troop,
        ?string $batchName,
        BatchNameMode $batchNameMode,
        ?int $requestedByUserId
    ): self {
        return new self(
            id: null,
            companyId: $companyId,
            code: $code,
            number: $number,
            status: EntryOrderStatus::DRAFT,
            kind: $kind,
            troop: $troop,
            batchName: self::cleanBatchName($batchName),
            batchNameMode: $batchNameMode,
            batchId: null,
            requestedByUserId: $requestedByUserId
        );
    }

    /**
     * Rewrites a draft: anything the purchase declares can still change.
     *
     * @throws EntryOrderDomainException
     */
    public function updateDraft(EntryTroop $troop, ?string $batchName, BatchNameMode $batchNameMode): void
    {
        if (!$this->status->isEditable()) {
            throw EntryOrderDomainException::invalid(
                "La orden {$this->code} está {$this->status->label()}: sólo un borrador se puede modificar.",
                'NOT_EDITABLE'
            );
        }

        $this->troop = $troop;
        $this->batchName = self::cleanBatchName($batchName);
        $this->batchNameMode = $batchNameMode;
        $this->troopReplaced = true;
    }

    /**
     * A draft may lack anything but its origin. Closing the purchase needs the whole troop and the
     * name of the batch it creates.
     *
     * @throws EntryOrderDomainException
     */
    public function assertReadyToConfirm(): void
    {
        if ($this->status !== EntryOrderStatus::DRAFT) {
            throw EntryOrderDomainException::invalidStateTransition($this->status->label(), EntryOrderStatus::AWAITING_DTE->label());
        }

        $this->troop->assertComplete();

        if ($this->batchName === null) {
            throw EntryOrderDomainException::invalid(
                'El lote necesita un nombre. Si la compra es de una subasta, cargá la terminación y se sugiere solo.',
                'BATCH_NAME_MISSING',
                'batch_name'
            );
        }
    }

    /**
     * Draft → awaiting DTE. The purchase is closed and its batch exists, empty, until the animals
     * of its DTEs arrive.
     *
     * @throws EntryOrderDomainException
     */
    public function confirm(int $batchId): void
    {
        $this->assertReadyToConfirm();

        $this->batchId = $batchId;
        $this->status = EntryOrderStatus::AWAITING_DTE;
        $this->confirmedAt = new DateTimeImmutable();
    }

    /**
     * Records a DTE: how many head are on their way. A DTE with more head than bought is recorded
     * anyway: the difference is raised as an incident, and the head the order declared are left
     * as they were.
     *
     * @return EntryOrderIncidentEntity[] the incidents this DTE raised
     *
     * @throws EntryOrderDomainException
     */
    public function recordDte(EntryOrderDteEntity $dte, ?int $userId = null): array
    {
        if (!$this->status->acceptsDte()) {
            throw EntryOrderDomainException::invalid(
                "La orden {$this->code} está {$this->status->label()} y no admite más DTE.",
                'ENTRY_ORDER_NOT_ACCEPTING_DTE'
            );
        }

        if ($dte->getHeadCount() < 1) {
            throw EntryOrderDomainException::invalid('El DTE tiene que declarar al menos una cabeza.', 'HEAD_COUNT_INVALID', 'head_count');
        }

        foreach ($this->dtes as $loaded) {
            if (strcasecmp($loaded->getDteNumber(), $dte->getDteNumber()) === 0) {
                throw EntryOrderDomainException::invalid(
                    "El DTE {$dte->getDteNumber()} ya está cargado en esta orden.",
                    'DTE_DUPLICATED',
                    'dte_number'
                );
            }
        }

        $before = $this->withDteCount();
        $this->dtes[] = $dte;
        $raised = $this->raiseHeadExcess($before, $dte->getDteNumber(), "con el DTE {$dte->getDteNumber()}", $userId);

        if ($this->firstDteAt === null) {
            $this->firstDteAt = new DateTimeImmutable();
        }

        $this->refreshStatus();

        return $raised;
    }

    /**
     * Corrects the head a DTE declares, when they were loaded wrong. Never below what was already
     * received or declared missing on it: more animals than the paper says is an excess to settle
     * with the provider, not a loading mistake. Open incidents are left open — someone writes down
     * how they were settled — but the ones the correction left without a difference are returned.
     *
     * @return array{raised: EntryOrderIncidentEntity[], no_longer_differ: EntryOrderIncidentEntity[], from: int}
     *
     * @throws EntryOrderDomainException
     */
    public function correctDteHeadCount(int $dteId, int $headCount, string $reason, ?int $userId = null): array
    {
        if (!$this->status->acceptsReception()) {
            throw EntryOrderDomainException::invalid(
                "La orden {$this->code} está {$this->status->label()}: sus DTE ya no se corrigen.",
                'ORDER_NOT_RECEIVING'
            );
        }

        $dte = $this->findDte($dteId);

        if ($headCount < 1) {
            throw EntryOrderDomainException::invalid('El DTE tiene que declarar al menos una cabeza.', 'HEAD_COUNT_INVALID', 'head_count');
        }

        if ($headCount < $dte->accountedCount()) {
            throw EntryOrderDomainException::invalid(
                "El DTE {$dte->getDteNumber()} ya tiene {$dte->receivedCount()} recibidas y {$dte->getMissingHeadCount()} que no llegarán: "
                . "no puede declarar menos de {$dte->accountedCount()} cabezas.",
                'HEAD_COUNT_BELOW_ACCOUNTED',
                'head_count'
            );
        }

        if ($headCount === $dte->getHeadCount()) {
            throw EntryOrderDomainException::invalid("El DTE {$dte->getDteNumber()} ya declara {$headCount} cabezas.", 'HEAD_COUNT_UNCHANGED', 'head_count');
        }

        if (trim($reason) === '') {
            throw EntryOrderDomainException::reasonRequired('corregir las cabezas del DTE');
        }

        $from = $dte->getHeadCount();
        $before = $this->withDteCount();
        $dte->correctHeadCount($headCount);
        $raised = $this->raiseHeadExcess($before, $dte->getDteNumber(), "con la corrección del DTE {$dte->getDteNumber()}", $userId);

        $noLongerDiffer = array_values(array_filter($this->incidents, fn (EntryOrderIncidentEntity $i) => $i->getId() !== null && $i->isOpen() && (
            ($i->getType() === EntryOrderIncidentType::ARRIVAL_EXCESS && self::sameDte($i, $dte) && $dte->excessCount() === 0)
            || ($i->getType() === EntryOrderIncidentType::EXCESS_HEAD && $this->withDteCount() <= (int) $this->troop->headCount)
        )));

        $this->refreshStatus();

        return ['raised' => $raised, 'no_longer_differ' => $noLongerDiffer, 'from' => $from];
    }

    /**
     * Animals of a DTE arrived. Two ways:
     *
     *  - By head (`$receivedHeads`, the manual reception): the person confirms how many head
     *    arrived, and that closes the DTE. Fewer than in transit are declared as never arriving,
     *    more are an arrival excess: either way an incident, never a block. Caravans written with
     *    it are optional; the head without one stay "uncaravaned" until their caravan is written.
     *  - By caravan (an ING-03 sheet, or caravans written later): each line is a caravan, already
     *    created by the caller. It first identifies head already received without caravan, and
     *    only the rest are new head. Head of the DTE left out stay in transit unless declared as
     *    never arriving, with a reason. On a closed order caravans can only identify head received.
     *
     * More animals than the DTE declares, or more of a sex than the order declares, are received
     * anyway and raised as incidents.
     *
     * @param EntryOrderAnimalEntity[] $animals
     * @return EntryOrderIncidentEntity[] the incidents this reception raised
     *
     * @throws EntryOrderDomainException
     */
    public function receive(
        EntryOrderDteEntity $dte,
        array $animals,
        int $missingHead,
        ?string $reason,
        ?int $userId,
        ?string $sheetLabel = null,
        ?int $receivedHeads = null
    ): array {
        if (!in_array($dte, $this->dtes, true)) {
            throw EntryOrderDomainException::invalid('El DTE no pertenece a esta orden.', 'DTE_NOT_IN_ORDER', 'dte_id');
        }

        $this->assertReceives($dte, count($animals), $receivedHeads);

        if ($animals === [] && $missingHead === 0 && $receivedHeads === null) {
            throw EntryOrderDomainException::invalid('La recepción no trae ninguna caravana.', 'NOTHING_TO_RECEIVE');
        }

        $reason = trim((string) $reason);

        if ($receivedHeads === null && $missingHead > 0 && $reason === '') {
            throw EntryOrderDomainException::reasonRequired('declarar cabezas que no llegarán a');
        }

        $pendingBefore = $dte->pendingCount();
        $excessBefore = $dte->excessCount();
        $bySexBefore = [
            AnimalSex::MALE->value => $this->receivedCountBySex(AnimalSex::MALE->value),
            AnimalSex::FEMALE->value => $this->receivedCountBySex(AnimalSex::FEMALE->value),
        ];

        if ($receivedHeads !== null) {
            $dte->countHeads($receivedHeads);
        }

        // A caravan first identifies a head already received; only the rest is a new head.
        $dte->identify(count($animals));

        foreach ($animals as $animal) {
            $dte->addAnimal($animal);
        }

        if ($receivedHeads !== null) {
            // Confirming the head closes the DTE: what did not come will not come.
            $missingHead = max(0, $pendingBefore - $receivedHeads);
            $reason = $reason !== '' ? $reason : "Se recibieron {$receivedHeads} de {$pendingBefore} cabezas en tránsito";
        }

        if ($missingHead > $dte->pendingCount()) {
            throw EntryOrderDomainException::invalid(
                "Al DTE {$dte->getDteNumber()} le quedan {$dte->pendingCount()} cabezas en tránsito: no pueden faltar {$missingHead}.",
                'MISSING_EXCEEDS_PENDING',
                'missing_head_count'
            );
        }

        $raised = [];

        if ($missingHead > 0) {
            $raised[] = $this->declareMissing($dte, $missingHead, $reason, $userId);
        }

        $excess = $dte->excessCount() - $excessBefore;

        if ($excess > 0) {
            // Counted head have no caravan to name: only the caravans written are listed.
            $tags = $receivedHeads === null
                ? array_map(fn (EntryOrderAnimalEntity $a) => $a->getIdentification(), array_slice($animals, -$excess))
                : [];
            $where = $sheetLabel !== null ? " (hoja {$sheetLabel})" : '';

            $raised[] = $this->raiseIncident(
                EntryOrderIncidentType::ARRIVAL_EXCESS,
                "El DTE {$dte->getDteNumber()} declara {$dte->getHeadCount()} cabezas; llegaron {$dte->receivedCount()} (+{$excess}){$where}"
                    . ($tags !== [] ? ': ' . implode(', ', $tags) : '') . '.',
                ['declared' => $dte->getHeadCount(), 'received' => $dte->receivedCount(), 'excess' => $excess, 'caravans' => $tags, 'receipt_sheet' => $sheetLabel],
                $dte->getDteNumber(),
                $userId
            );
        }

        if ($this->troop->sexComposition === SexComposition::MIXED) {
            $sexes = [
                [AnimalSex::MALE->value, (int) $this->troop->maleCount, 'machos', EntryOrderIncidentType::EXCESS_MALES],
                [AnimalSex::FEMALE->value, (int) $this->troop->femaleCount, 'hembras', EntryOrderIncidentType::EXCESS_FEMALES],
            ];

            foreach ($sexes as [$sex, $declared, $word, $type]) {
                $after = $this->receivedCountBySex($sex);

                foreach (self::excessOf($declared, $bySexBefore[$sex], $after) as $over) {
                    $raised[] = $this->raiseIncident(
                        $type,
                        "Se declararon {$declared} {$word}; con esta recepción del DTE {$dte->getDteNumber()} llegaron {$after} (+{$over}).",
                        ['declared' => $declared, 'received' => $after, 'excess' => $over],
                        $dte->getDteNumber(),
                        $userId
                    );
                }
            }
        }

        $this->refreshStatus();

        return $raised;
    }

    /**
     * Caravans of a reception that arrived of a breed the purchase does not declare: they are
     * received with it, and the difference is left to settle with the provider.
     *
     * @param array<string, string> $caravans caravan → the breed and coat written for it
     */
    public function reportBreedMismatch(EntryOrderDteEntity $dte, array $caravans, ?string $sheetLabel, ?int $userId): EntryOrderIncidentEntity
    {
        $where = $sheetLabel !== null ? " (hoja {$sheetLabel})" : '';
        $listed = implode(', ', array_map(fn (string $tag, string $breed) => "{$tag} ({$breed})", array_keys($caravans), $caravans));
        $count = count($caravans);

        return $this->raiseIncident(
            EntryOrderIncidentType::BREED_MISMATCH,
            "En el DTE {$dte->getDteNumber()}{$where} " . ($count === 1 ? 'llegó 1 caravana' : "llegaron {$count} caravanas")
                . " de una raza que la compra no declara: {$listed}.",
            ['caravans' => array_keys($caravans), 'breeds' => $caravans, 'receipt_sheet' => $sheetLabel],
            $dte->getDteNumber(),
            $userId
        );
    }

    /**
     * Whether the DTE can take this reception. Confirming head needs head in transit on an order
     * that waits for animals. Caravans need head in transit or head received without caravan; on
     * a closed order they can only identify the latter.
     *
     * @throws EntryOrderDomainException
     */
    private function assertReceives(EntryOrderDteEntity $dte, int $caravans, ?int $receivedHeads): void
    {
        $open = $this->status->acceptsReception();
        $uncaravaned = $dte->getUncaravanedHeadCount();

        if (!$open && ($receivedHeads !== null || $uncaravaned === 0)) {
            throw EntryOrderDomainException::invalid(
                "La orden {$this->code} está {$this->status->label()} y no tiene hacienda por recibir.",
                'ORDER_NOT_RECEIVING'
            );
        }

        if (!$open && $caravans > $uncaravaned) {
            throw EntryOrderDomainException::invalid(
                "La orden {$this->code} está {$this->status->label()}: sólo se pueden cargar las caravanas de las {$uncaravaned} cabezas recibidas sin caravana.",
                'CARAVANS_EXCEED_UNCARAVANED',
                'animals'
            );
        }

        $nothing = $receivedHeads !== null ? $dte->pendingCount() === 0 : $dte->toIdentifyCount() === 0;

        if ($nothing) {
            throw EntryOrderDomainException::invalid(
                "El DTE {$dte->getDteNumber()} no tiene cabezas en tránsito.",
                'DTE_NOTHING_PENDING',
                'dte_id'
            );
        }

        if ($receivedHeads !== null && $caravans > $uncaravaned + $receivedHeads) {
            throw EntryOrderDomainException::invalid(
                "Se cargaron {$caravans} caravanas para {$receivedHeads} cabezas recibidas: no puede haber más caravanas que cabezas.",
                'CARAVANS_EXCEED_HEADS',
                'received_head_count'
            );
        }
    }

    /**
     * Issues the ING-03 receipt sheet of a DTE: a blank line per head still in transit. A DTE has
     * one sheet expected back at a time, so an earlier one still out is replaced. Without a
     * weighing or a reference mode given, the sheet is like the last one of the DTE — or of the
     * order — was; the first one is written in words.
     *
     * @throws EntryOrderDomainException
     */
    public function issueReceiptSheet(
        int $dteId,
        ?int $userId,
        ?WeighingMode $weighingMode = null,
        ?ReferenceMode $referenceMode = null
    ): EntryOrderReceiptSheetEntity {
        $dte = $this->findDte($dteId);

        $open = $this->status->acceptsReception() && $dte->toIdentifyCount() > 0;

        if (!$open && $dte->getUncaravanedHeadCount() === 0) {
            throw EntryOrderDomainException::invalid(
                "El DTE {$dte->getDteNumber()} no tiene cabezas en tránsito ni sin caravana: no hay nada que recibir en planilla.",
                'DTE_NOTHING_PENDING',
                'dte_id'
            );
        }

        $last = $this->lastReceiptSheet($dteId);
        $weighingMode ??= $last?->getWeighingMode() ?? WeighingMode::INDIVIDUAL;
        $referenceMode ??= $last?->getReferenceMode() ?? ReferenceMode::WRITTEN;

        foreach ($this->receiptSheets as $sheet) {
            if ($sheet->getDteId() === $dteId && $sheet->getStatus()->isActive()) {
                $sheet->replace();
            }
        }

        $number = 1 + array_reduce($this->receiptSheets, fn (int $max, EntryOrderReceiptSheetEntity $s) => max($max, $s->getNumber()), 0);
        $sheet = EntryOrderReceiptSheetEntity::issue($number, $dte, $userId, $weighingMode, $referenceMode);
        $this->receiptSheets[] = $sheet;

        return $sheet;
    }

    /**
     * The newest sheet of the DTE, or of the order when the DTE has none: what a new one copies.
     */
    private function lastReceiptSheet(int $dteId): ?EntryOrderReceiptSheetEntity
    {
        $sheets = $this->receiptSheets;
        usort($sheets, fn (EntryOrderReceiptSheetEntity $a, EntryOrderReceiptSheetEntity $b) => $b->getNumber() <=> $a->getNumber());

        foreach ($sheets as $sheet) {
            if ($sheet->getDteId() === $dteId) {
                return $sheet;
            }
        }

        return $sheets[0] ?? null;
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function findReceiptSheet(int $sheetId): EntryOrderReceiptSheetEntity
    {
        foreach ($this->receiptSheets as $sheet) {
            if ($sheet->getId() === $sheetId) {
                return $sheet;
            }
        }

        throw EntryOrderDomainException::invalid("La hoja de recepción no es de la orden {$this->code}.", 'RECEIPT_SHEET_NOT_IN_ORDER', 'receipt_sheet_id');
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function findDte(int $dteId): EntryOrderDteEntity
    {
        foreach ($this->dtes as $dte) {
            if ($dte->getId() === $dteId) {
                return $dte;
            }
        }

        throw EntryOrderDomainException::invalid('El DTE no pertenece a esta orden.', 'DTE_NOT_IN_ORDER', 'dte_id');
    }

    /**
     * The DTE by its number — the one just loaded has no id yet — or null.
     */
    public function findDteByNumber(string $dteNumber): ?EntryOrderDteEntity
    {
        foreach ($this->dtes as $dte) {
            if (strcasecmp($dte->getDteNumber(), $dteNumber) === 0) {
                return $dte;
            }
        }

        return null;
    }

    /**
     * No more will come. From AWAITING_DTE once some DTE was loaded (the seller will not send the
     * rest), or from IN_TRANSIT: the head of each DTE still in transit are declared missing, with
     * an incident. Without any DTE the order is cancelled instead.
     *
     * @throws EntryOrderDomainException
     */
    public function closeIncomplete(string $reason, ?int $userId = null): void
    {
        $allowed = ($this->status === EntryOrderStatus::AWAITING_DTE && $this->dtes !== [])
            || $this->status === EntryOrderStatus::IN_TRANSIT;

        if (!$allowed) {
            throw EntryOrderDomainException::invalidStateTransition($this->status->label(), EntryOrderStatus::CLOSED_INCOMPLETE->label());
        }

        if (trim($reason) === '') {
            throw EntryOrderDomainException::reasonRequired('cerrar incompleta');
        }

        foreach ($this->dtes as $dte) {
            if ($dte->pendingCount() > 0) {
                $this->declareMissing($dte, $dte->pendingCount(), trim($reason), $userId);
            }
        }

        // Head bought that never got a document are also something to settle with the seller.
        $withoutDte = $this->pendingDteCount();

        if ($withoutDte > 0) {
            $words = $withoutDte === 1 ? 'cabeza nunca tuvo' : 'cabezas nunca tuvieron';

            $this->raiseIncident(
                EntryOrderIncidentType::MISSING_DTE,
                "Orden por {$this->troop->headCount} cabezas; {$withoutDte} {$words} DTE. Motivo: " . trim($reason),
                ['declared' => $this->troop->headCount, 'with_dte' => $this->withDteCount(), 'missing_dte' => $withoutDte, 'reason' => trim($reason)],
                null,
                $userId
            );
        }

        // Closed incomplete once the head received without caravan are identified, if any.
        $this->closingReason = trim($reason);
        $this->finish();
    }

    /**
     * The purchase did not happen. Never once a DTE was loaded: the order is closed incomplete
     * instead. A draft is discarded without a reason; a confirmed order needs one.
     *
     * @throws EntryOrderDomainException
     */
    public function cancel(?string $reason): void
    {
        $allowed = $this->status === EntryOrderStatus::DRAFT
            || ($this->status === EntryOrderStatus::AWAITING_DTE && $this->dtes === []);

        if (!$allowed) {
            throw EntryOrderDomainException::invalidStateTransition($this->status->label(), EntryOrderStatus::CANCELLED->label());
        }

        $reason = trim((string) $reason);

        if ($this->status === EntryOrderStatus::AWAITING_DTE && $reason === '') {
            throw EntryOrderDomainException::reasonRequired('anular');
        }

        $this->status = EntryOrderStatus::CANCELLED;
        $this->closingReason = $reason !== '' ? $reason : null;
        $this->closedAt = new DateTimeImmutable();
    }

    /**
     * Writes down what was agreed with the provider. Neither the status nor anything else changes.
     *
     * @throws EntryOrderDomainException
     */
    public function resolveIncident(int $incidentId, string $resolution, ?int $userId): EntryOrderIncidentEntity
    {
        foreach ($this->incidents as $incident) {
            if ($incident->getId() === $incidentId) {
                $incident->resolve($resolution, $userId);

                return $incident;
            }
        }

        throw EntryOrderDomainException::invalid('La novedad no pertenece a esta orden.', 'INCIDENT_NOT_FOUND');
    }

    /**
     * Stamps the paper. A draft is not printed: the sheet is the record of a closed purchase.
     *
     * @throws EntryOrderDomainException
     */
    public function markPrinted(): void
    {
        if ($this->status === EntryOrderStatus::DRAFT) {
            throw EntryOrderDomainException::invalid(
                'Un borrador no se imprime: confirmá la compra primero.',
                'DRAFT_NOT_PRINTABLE'
            );
        }

        if ($this->printedAt === null) {
            $this->printedAt = new DateTimeImmutable();
        }
    }

    /**
     * Head declared by the DTEs. May exceed the head bought: a DTE with head in excess is loaded anyway.
     */
    public function withDteCount(): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->getHeadCount(), $this->dtes));
    }

    /**
     * Head bought still waiting for their document.
     */
    public function pendingDteCount(): int
    {
        return max(0, (int) $this->troop->headCount - $this->withDteCount());
    }

    /**
     * Head of the DTEs still on their way.
     */
    public function inTransitCount(): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->pendingCount(), $this->dtes));
    }

    public function receivedCount(): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->receivedCount(), $this->dtes));
    }

    /**
     * Head received whose caravan is still to write.
     */
    public function uncaravanedCount(): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->getUncaravanedHeadCount(), $this->dtes));
    }

    /**
     * Caravans received of a sex: only meaningful when the caravans were loaded (the detail).
     */
    public function receivedCountBySex(string $sex): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->receivedCountBySex($sex), $this->dtes));
    }

    /**
     * Caravans received on a category line: only meaningful when the caravans were loaded (the detail).
     */
    public function receivedCountByCategory(int $position): int
    {
        $count = 0;

        foreach ($this->dtes as $dte) {
            foreach ($dte->getAnimals() as $animal) {
                $count += $animal->getCategoryPosition() === $position ? 1 : 0;
            }
        }

        return $count;
    }

    public function missingCount(): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->getMissingHeadCount(), $this->dtes));
    }

    public function openIncidentsCount(): int
    {
        return count(array_filter($this->incidents, fn (EntryOrderIncidentEntity $i) => $i->isOpen()));
    }

    /**
     * The DTEs not yet stored: the one being loaded now.
     *
     * @return EntryOrderDteEntity[]
     */
    public function unsavedDtes(): array
    {
        return array_values(array_filter($this->dtes, fn (EntryOrderDteEntity $d) => $d->getId() === null));
    }

    /**
     * Stored DTEs whose declared or missing head changed in this operation.
     *
     * @return EntryOrderDteEntity[]
     */
    public function changedDtes(): array
    {
        return array_values(array_filter($this->dtes, fn (EntryOrderDteEntity $d) => $d->getId() !== null && $d->isChanged()));
    }

    /**
     * @return EntryOrderIncidentEntity[]
     */
    public function unsavedIncidents(): array
    {
        return array_values(array_filter($this->incidents, fn (EntryOrderIncidentEntity $i) => $i->getId() === null));
    }

    /**
     * @return EntryOrderIncidentEntity[]
     */
    public function resolvedIncidents(): array
    {
        return array_values(array_filter($this->incidents, fn (EntryOrderIncidentEntity $i) => $i->getId() !== null && $i->isResolvedNow()));
    }

    public function isTroopReplaced(): bool
    {
        return $this->troopReplaced;
    }

    /**
     * What the order is waiting for: first the documents, then the animals. Called after a DTE is
     * loaded or corrected and after every reception.
     */
    private function refreshStatus(): void
    {
        if ($this->status->awaitsIdentification()) {
            $this->finish();

            return;
        }

        if (!$this->status->acceptsReception()) {
            return;
        }

        if ($this->pendingDteCount() > 0) {
            $this->status = EntryOrderStatus::AWAITING_DTE;   // waiting for documents

            return;
        }

        if ($this->inTransitCount() > 0) {
            $this->status = EntryOrderStatus::IN_TRANSIT;     // waiting for the animals

            return;
        }

        $this->finish();
    }

    /**
     * Nothing more arrives. While some head received have no caravan the order waits for them
     * (RECEIVED): it is not finished until every animal is in the system. Then it closes: complete,
     * or incomplete when head did not arrive or it was closed by hand (its reason kept).
     */
    private function finish(): void
    {
        if ($this->uncaravanedCount() > 0) {
            $this->status = EntryOrderStatus::RECEIVED;       // waiting for the caravans

            return;
        }

        if ($this->missingCount() > 0 || $this->closingReason !== null) {
            $this->status = EntryOrderStatus::CLOSED_INCOMPLETE;
            $this->closingReason ??= $this->missingSummary();
        } else {
            $this->status = EntryOrderStatus::COMPLETED;
        }

        $this->closedAt = new DateTimeImmutable();
    }

    /**
     * Why an order closed by itself: what did not arrive and why, so it reads at the top of the
     * order without opening the incidents. "2 cabezas no llegaron. Motivo: murieron en el viaje".
     */
    private function missingSummary(): string
    {
        $count = $this->missingCount();
        $reasons = [];

        foreach ($this->incidents as $incident) {
            $reason = $incident->getType() === EntryOrderIncidentType::MISSING_HEAD ? ($incident->getMetadata()['reason'] ?? null) : null;

            if (is_string($reason) && trim($reason) !== '') {
                $reasons[trim($reason)] = true;
            }
        }

        $what = $count === 1 ? '1 cabeza no llegó' : "{$count} cabezas no llegaron";
        $why = array_keys($reasons);

        return $why === [] ? "{$what}." : "{$what}. Motivo: " . implode('; ', $why);
    }

    private function declareMissing(EntryOrderDteEntity $dte, int $head, string $reason, ?int $userId): EntryOrderIncidentEntity
    {
        $dte->declareMissing($head);
        $words = $head === 1 ? 'cabeza' : 'cabezas';
        $verb = $head === 1 ? 'no llegará' : 'no llegarán';

        return $this->raiseIncident(
            EntryOrderIncidentType::MISSING_HEAD,
            "{$head} {$words} del DTE {$dte->getDteNumber()} {$verb}. Motivo: {$reason}",
            ['count' => $head, 'reason' => $reason],
            $dte->getDteNumber(),
            $userId
        );
    }

    /**
     * The head the DTEs declare over the head bought, raised once for what this change added.
     *
     * @return EntryOrderIncidentEntity[]
     */
    private function raiseHeadExcess(int $before, string $dteNumber, string $how, ?int $userId): array
    {
        $raised = [];

        foreach (self::excessOf((int) $this->troop->headCount, $before, $this->withDteCount()) as $excess) {
            $raised[] = $this->raiseIncident(
                EntryOrderIncidentType::EXCESS_HEAD,
                "Orden por {$this->troop->headCount} cabezas; {$how} suman {$this->withDteCount()} (+{$excess}).",
                ['declared' => $this->troop->headCount, 'with_dte' => $this->withDteCount(), 'excess' => $excess],
                $dteNumber,
                $userId
            );
        }

        return $raised;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function raiseIncident(EntryOrderIncidentType $type, string $detail, array $metadata, ?string $dteNumber, ?int $userId): EntryOrderIncidentEntity
    {
        $incident = EntryOrderIncidentEntity::raise($type, $detail, $metadata, $dteNumber, $userId);
        $this->incidents[] = $incident;

        return $incident;
    }

    private static function sameDte(EntryOrderIncidentEntity $incident, EntryOrderDteEntity $dte): bool
    {
        return ($incident->getDteId() !== null && $incident->getDteId() === $dte->getId())
            || ($incident->getDteNumber() !== null && strcasecmp($incident->getDteNumber(), $dte->getDteNumber()) === 0);
    }

    /**
     * The excess a change added over what was declared, as a one-element list, or none.
     *
     * @return list<int>
     */
    private static function excessOf(int $declared, int $before, int $after): array
    {
        $excess = $after - max($declared, $before);

        return $after > $declared && $excess > 0 ? [$excess] : [];
    }

    /**
     * Null while a draft has no name yet: it is demanded when the purchase is confirmed.
     */
    private static function cleanBatchName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) $name));

        return $name !== '' ? $name : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getStatus(): EntryOrderStatus
    {
        return $this->status;
    }

    public function getKind(): TransferOrderKind
    {
        return $this->kind;
    }

    public function getTroop(): EntryTroop
    {
        return $this->troop;
    }

    public function getBatchName(): ?string
    {
        return $this->batchName;
    }

    public function getBatchNameMode(): BatchNameMode
    {
        return $this->batchNameMode;
    }

    public function getBatchId(): ?int
    {
        return $this->batchId;
    }

    public function getRequestedByUserId(): ?int
    {
        return $this->requestedByUserId;
    }

    public function getConfirmedAt(): ?DateTimeInterface
    {
        return $this->confirmedAt;
    }

    public function getPrintedAt(): ?DateTimeInterface
    {
        return $this->printedAt;
    }

    public function getFirstDteAt(): ?DateTimeInterface
    {
        return $this->firstDteAt;
    }

    public function getClosedAt(): ?DateTimeInterface
    {
        return $this->closedAt;
    }

    public function getClosingReason(): ?string
    {
        return $this->closingReason;
    }

    /**
     * @return EntryOrderDteEntity[]
     */
    public function getDtes(): array
    {
        return $this->dtes;
    }

    /**
     * @return EntryOrderIncidentEntity[]
     */
    public function getIncidents(): array
    {
        return $this->incidents;
    }

    /**
     * @return EntryOrderReceiptSheetEntity[]
     */
    public function getReceiptSheets(): array
    {
        return $this->receiptSheets;
    }

    /**
     * @return EntryOrderReceiptSheetEntity[]
     */
    public function unsavedReceiptSheets(): array
    {
        return array_values(array_filter($this->receiptSheets, fn (EntryOrderReceiptSheetEntity $s) => $s->getId() === null));
    }

    /**
     * @return EntryOrderReceiptSheetEntity[]
     */
    public function changedReceiptSheets(): array
    {
        return array_values(array_filter($this->receiptSheets, fn (EntryOrderReceiptSheetEntity $s) => $s->getId() !== null && $s->isChanged()));
    }

    /**
     * @return TransferOrderHistoryEntity[]
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * Display name of something the order points to: provider, farm, farm_renspa, batch,
     * requested_by.
     */
    public function name(string $key): ?string
    {
        return $this->names[$key] ?? null;
    }
}
