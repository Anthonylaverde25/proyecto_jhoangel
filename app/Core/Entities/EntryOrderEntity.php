<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchNameMode;
use App\Core\Enums\EntryOrderIncidentType;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\ReceptionMethod;
use App\Core\Enums\ReceptionStatus;
use App\Core\Enums\SexComposition;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\ValueObjects\EntryTroop;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The purchase of an external troop. It is born without caravans: they only exist once the
 * official transit document (DTE) that lists them is loaded, and even then they are in transit
 * until each one is received at the chute or by hand.
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
     * Draft → awaiting DTE. The purchase is closed and its batch exists, empty, until the document
     * with the caravans arrives.
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
     * Records a DTE and the caravans it lists, all in transit. The caravans were already created by
     * the caller. A DTE with more head (or more of a sex) than bought is recorded anyway: the
     * difference is raised as an incident, and the head the order declared are left as they were.
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

        if ($dte->getAnimals() === []) {
            throw EntryOrderDomainException::invalid('El DTE no trae caravanas.', 'DTE_EMPTY', 'animals');
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
        $beforeBySex = [
            AnimalSex::MALE->value => $this->withDteCountBySex(AnimalSex::MALE->value),
            AnimalSex::FEMALE->value => $this->withDteCountBySex(AnimalSex::FEMALE->value),
        ];

        $this->dtes[] = $dte;
        $raised = [];

        foreach (self::excessOf($this->troop->headCount, $before, $this->withDteCount()) as $excess) {
            $raised[] = $this->raiseIncident(
                EntryOrderIncidentType::EXCESS_HEAD,
                "Orden por {$this->troop->headCount} cabezas; con el DTE {$dte->getDteNumber()} suman {$this->withDteCount()} (+{$excess}).",
                ['declared' => $this->troop->headCount, 'with_dte' => $this->withDteCount(), 'excess' => $excess],
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
                $after = $this->withDteCountBySex($sex);

                foreach (self::excessOf($declared, $beforeBySex[$sex], $after) as $excess) {
                    $raised[] = $this->raiseIncident(
                        $type,
                        "Se declararon {$declared} {$word}; con el DTE {$dte->getDteNumber()} son {$after} (+{$excess}).",
                        ['declared' => $declared, 'with_dte' => $after, 'excess' => $excess],
                        $dte->getDteNumber(),
                        $userId
                    );
                }
            }
        }

        if ($this->firstDteAt === null) {
            $this->firstDteAt = new DateTimeImmutable();
        }

        $this->refreshStatus();

        return $raised;
    }

    /**
     * The animals arrived. Received caravans come into possession; the ones declared missing never
     * will. Pending caravans left out of both lists stay in transit: they arrive later. The
     * caller already created the movements and weights of the received ones.
     *
     * @param list<array{caravan_id: int, movement_id: ?int, weight: ?float}> $received
     * @param int[] $missing caravan ids that will not arrive
     *
     * @throws EntryOrderDomainException
     */
    public function receive(
        array $received,
        array $missing,
        string $receivedAt,
        ReceptionMethod $method,
        ?int $userId,
        ?string $reason = null
    ): void {
        if (!$this->status->acceptsReception() || $this->inTransitCount() === 0) {
            throw EntryOrderDomainException::invalid(
                "La orden {$this->code} está {$this->status->label()} y no tiene hacienda por recibir.",
                'ORDER_NOT_RECEIVING'
            );
        }

        if ($received === [] && $missing === []) {
            throw EntryOrderDomainException::invalid('La recepción no trae ninguna caravana.', 'NOTHING_TO_RECEIVE');
        }

        $reason = trim((string) $reason);

        if ($missing !== [] && $reason === '') {
            throw EntryOrderDomainException::reasonRequired('declarar caravanas que no llegarán a');
        }

        foreach ($received as $line) {
            $this->pendingAnimal($line['caravan_id'])
                ->markReceived($receivedAt, $method, $userId, $line['movement_id'], $line['weight']);
        }

        if ($missing !== []) {
            $this->declareMissing($missing, $reason, $userId);
        }

        $this->refreshStatus();
    }

    /**
     * Issues the ING-03 receipt sheet of a DTE: its caravans still in transit, in print order. A
     * DTE has one sheet expected back at a time, so an earlier one still out is replaced. Without a
     * weighing given, the sheet weighs like the last one of the DTE — or of the order — did.
     *
     * @throws EntryOrderDomainException
     */
    public function issueReceiptSheet(int $dteId, ?int $userId, ?WeighingMode $weighingMode = null): EntryOrderReceiptSheetEntity
    {
        $dte = null;
        foreach ($this->dtes as $candidate) {
            if ($candidate->getId() === $dteId) {
                $dte = $candidate;
            }
        }

        if ($dte === null) {
            throw EntryOrderDomainException::invalid('El DTE no pertenece a esta orden.', 'DTE_NOT_IN_ORDER', 'dte_id');
        }

        $pending = array_values(array_filter($dte->getAnimals(), fn (EntryOrderAnimalEntity $a) => $a->isPending()));

        if (!$this->status->acceptsReception() || $pending === []) {
            throw EntryOrderDomainException::invalid(
                "El DTE {$dte->getDteNumber()} no tiene caravanas en tránsito: no hay nada que recibir en planilla.",
                'DTE_NOTHING_IN_TRANSIT',
                'dte_id'
            );
        }

        usort($pending, fn (EntryOrderAnimalEntity $a, EntryOrderAnimalEntity $b) => strnatcasecmp($a->getIdentification(), $b->getIdentification()));

        $weighingMode ??= $this->lastWeighingMode($dteId);

        foreach ($this->receiptSheets as $sheet) {
            if ($sheet->getDteId() === $dteId && $sheet->getStatus()->isActive()) {
                $sheet->replace();
            }
        }

        $number = 1 + array_reduce($this->receiptSheets, fn (int $max, EntryOrderReceiptSheetEntity $s) => max($max, $s->getNumber()), 0);
        $sheet = EntryOrderReceiptSheetEntity::issue($number, $dte, array_map(fn (EntryOrderAnimalEntity $a) => $a->getCaravanId(), $pending), $userId, $weighingMode);
        $this->receiptSheets[] = $sheet;

        return $sheet;
    }

    private function lastWeighingMode(int $dteId): WeighingMode
    {
        $sheets = $this->receiptSheets;
        usort($sheets, fn (EntryOrderReceiptSheetEntity $a, EntryOrderReceiptSheetEntity $b) => $b->getNumber() <=> $a->getNumber());

        foreach ($sheets as $sheet) {
            if ($sheet->getDteId() === $dteId) {
                return $sheet->getWeighingMode();
            }
        }

        return ($sheets[0] ?? null)?->getWeighingMode() ?? WeighingMode::INDIVIDUAL;
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
     * Animals that arrived with a caravan no DTE of the order lists. They cannot come into
     * possession — a caravan exists only through its document — but the fact does not block the
     * reception: it stays as an incident to settle with the seller, who owes the DTE.
     *
     * @param list<array{identification: string, sex: ?string, breed?: ?string, coat?: ?string, weight: ?float, body_condition?: ?float}> $lines
     */
    public function reportUnlistedCaravans(array $lines, string $receivedAt, ?string $sheetLabel, ?int $userId): void
    {
        if ($lines === []) {
            return;
        }

        $count = count($lines);
        $tags = implode(', ', array_map(
            function (array $l): string {
                $traits = array_filter([
                    $l['sex'] ?? null,
                    trim(($l['breed'] ?? '') . ' ' . ($l['coat'] ?? '')) ?: null,
                    isset($l['weight']) ? "{$l['weight']} kg" : null,
                    isset($l['body_condition']) ? "EC {$l['body_condition']}" : null,
                ]);

                return $l['identification'] . ($traits !== [] ? ' (' . implode(', ', $traits) . ')' : '');
            },
            $lines
        ));
        $what = $count === 1 ? '1 animal llegó con una caravana' : "{$count} animales llegaron con caravanas";
        $where = $sheetLabel !== null ? " (hoja {$sheetLabel})" : '';

        $this->raiseIncident(
            EntryOrderIncidentType::UNLISTED_CARAVAN,
            "{$what} que no figura en ningún DTE de la orden{$where}: {$tags}. Hace falta el DTE para dar" . ($count === 1 ? 'la' : 'las') . ' de alta.',
            ['caravans' => $lines, 'received_at' => $receivedAt, 'receipt_sheet' => $sheetLabel],
            null,
            $userId
        );
    }

    /**
     * No more will come. From AWAITING_DTE once some DTE was loaded (the seller will not send the
     * rest), or from IN_TRANSIT: the caravans still pending become missing, with an incident.
     * Without any DTE the order is cancelled instead.
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

        $pending = [];
        foreach ($this->dtes as $dte) {
            foreach ($dte->getAnimals() as $animal) {
                if ($animal->isPending()) {
                    $pending[] = $animal->getCaravanId();
                }
            }
        }

        if ($pending !== []) {
            $this->declareMissing($pending, trim($reason), $userId);
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

        $this->status = EntryOrderStatus::CLOSED_INCOMPLETE;
        $this->closingReason = trim($reason);
        $this->closedAt = new DateTimeImmutable();
    }

    /**
     * The purchase did not happen. Never once a DTE was loaded: those caravans exist, so the order
     * is closed incomplete instead. A draft is discarded without a reason; a confirmed order needs one.
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
     * Caravans with a DTE. May exceed the head bought: a DTE with head in excess is loaded anyway.
     */
    public function withDteCount(): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->getHeadCount(), $this->dtes));
    }

    public function withDteCountBySex(string $sex): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->countBySex($sex), $this->dtes));
    }

    /**
     * Head bought still waiting for their document.
     */
    public function pendingDteCount(): int
    {
        return max(0, (int) $this->troop->headCount - $this->withDteCount());
    }

    public function inTransitCount(): int
    {
        return $this->countByReception(ReceptionStatus::PENDING);
    }

    public function receivedCount(): int
    {
        return $this->countByReception(ReceptionStatus::RECEIVED);
    }

    public function missingCount(): int
    {
        return $this->countByReception(ReceptionStatus::MISSING);
    }

    public function openIncidentsCount(): int
    {
        return count(array_filter($this->incidents, fn (EntryOrderIncidentEntity $i) => $i->isOpen()));
    }

    /**
     * The DTE that lists a caravan, or null when the caravan is not in this order.
     */
    public function dteOf(int $caravanId): ?EntryOrderDteEntity
    {
        foreach ($this->dtes as $dte) {
            if ($dte->findAnimal($caravanId) !== null) {
                return $dte;
            }
        }

        return null;
    }

    public function findAnimal(int $caravanId): ?EntryOrderAnimalEntity
    {
        return $this->dteOf($caravanId)?->findAnimal($caravanId);
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
     * Stored caravans whose reception changed in this operation.
     *
     * @return EntryOrderAnimalEntity[]
     */
    public function changedAnimals(): array
    {
        $changed = [];

        foreach ($this->dtes as $dte) {
            if ($dte->getId() === null) {
                continue;
            }

            foreach ($dte->getAnimals() as $animal) {
                if ($animal->isReceptionChanged()) {
                    $changed[] = $animal;
                }
            }
        }

        return $changed;
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
     * loaded and after every reception.
     */
    private function refreshStatus(): void
    {
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

        if ($this->missingCount() > 0) {
            $this->status = EntryOrderStatus::CLOSED_INCOMPLETE;
            $this->closingReason = $this->missingSummary();
        } else {
            $this->status = EntryOrderStatus::COMPLETED;
        }

        $this->closedAt = new DateTimeImmutable();
    }

    /**
     * Why an order closed by itself: what did not arrive and why, so it reads at the top of the
     * order without opening the incidents. "2 caravanas no llegaron. Motivo: murió en el viaje".
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

        $what = $count === 1 ? '1 caravana no llegó' : "{$count} caravanas no llegaron";
        $why = array_keys($reasons);

        return $why === [] ? "{$what}." : "{$what}. Motivo: " . implode('; ', $why);
    }

    /**
     * @param int[] $caravanIds pending caravans that will not arrive
     */
    private function declareMissing(array $caravanIds, string $reason, ?int $userId): void
    {
        $byDte = [];

        foreach ($caravanIds as $caravanId) {
            $animal = $this->pendingAnimal($caravanId);
            $animal->markMissing($userId);
            $byDte[(string) $this->dteOf($caravanId)?->getDteNumber()][] = $animal->getIdentification();
        }

        $tags = array_merge(...array_values($byDte));
        $count = count($tags);
        $dteNumbers = array_keys($byDte);
        $source = count($dteNumbers) === 1 ? "del DTE {$dteNumbers[0]}" : 'de los DTE ' . implode(', ', $dteNumbers);
        $words = $count === 1 ? 'caravana' : 'caravanas';
        $verb = $count === 1 ? 'no llegará' : 'no llegarán';

        $this->raiseIncident(
            EntryOrderIncidentType::MISSING_HEAD,
            "{$count} {$words} {$source} {$verb}: " . implode(', ', $tags) . ". Motivo: {$reason}",
            ['caravans' => $byDte, 'count' => $count, 'reason' => $reason],
            count($dteNumbers) === 1 ? (string) $dteNumbers[0] : null,
            $userId
        );
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

    /**
     * @throws EntryOrderDomainException
     */
    private function pendingAnimal(int $caravanId): EntryOrderAnimalEntity
    {
        $animal = $this->findAnimal($caravanId);

        if ($animal === null) {
            throw EntryOrderDomainException::invalid("La caravana {$caravanId} no está en la orden {$this->code}.", 'CARAVAN_NOT_IN_ORDER');
        }

        if (!$animal->isPending()) {
            throw EntryOrderDomainException::invalid(
                "La caravana {$animal->getIdentification()} ya está {$animal->getReceptionStatus()->label()}.",
                $animal->getReceptionStatus() === ReceptionStatus::RECEIVED ? 'CARAVAN_ALREADY_RECEIVED' : 'CARAVAN_MARKED_MISSING'
            );
        }

        return $animal;
    }

    private function countByReception(ReceptionStatus $status): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->countByReception($status), $this->dtes));
    }

    /**
     * The excess a DTE added over what was declared, as a one-element list, or none.
     *
     * @return list<int>
     */
    private static function excessOf(int $declared, int $before, int $after): array
    {
        $excess = $after - max($declared, $before);

        return $after > $declared && $excess > 0 ? [$excess] : [];
    }

    /**
     * @throws EntryOrderDomainException
     */
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
     * Display name of something the order points to: provider, farm, farm_renspa, category,
     * requested_by.
     */
    public function name(string $key): ?string
    {
        return $this->names[$key] ?? null;
    }
}
