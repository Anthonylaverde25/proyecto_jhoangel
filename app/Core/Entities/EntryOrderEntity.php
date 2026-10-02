<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchNameMode;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\SexComposition;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\ValueObjects\EntryTroop;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The purchase of an external troop. It is born without caravans: they only exist once the
 * official transit document (DTE) that lists them arrives, so a confirmed order waits in
 * AWAITING_DTE, and each DTE loaded against it brings part or all of the head bought.
 *
 * The status is never set by hand once confirmed: it follows the head the DTEs brought.
 */
final class EntryOrderEntity
{
    private bool $troopReplaced = false;

    /**
     * @param EntryOrderDteEntity[] $dtes
     * @param TransferOrderHistoryEntity[] $history the same history line as the other orders
     * @param array<string, ?string> $names display names of what the ids point to
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly string $code,
        private readonly int $number,
        private EntryOrderStatus $status,
        private readonly TransferOrderKind $kind,
        private EntryTroop $troop,
        private string $batchName,
        private BatchNameMode $batchNameMode,
        private ?int $batchId,
        private readonly ?int $requestedByUserId,
        private ?DateTimeInterface $confirmedAt = null,
        private ?DateTimeInterface $printedAt = null,
        private ?DateTimeInterface $firstDteAt = null,
        private ?DateTimeInterface $closedAt = null,
        private ?string $closingReason = null,
        private array $dtes = [],
        private readonly array $history = [],
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly array $names = []
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
        string $batchName,
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
    public function updateDraft(EntryTroop $troop, string $batchName, BatchNameMode $batchNameMode): void
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
     * Draft → awaiting DTE. The purchase is closed and its batch exists, empty, until the document
     * with the caravans arrives.
     *
     * @throws EntryOrderDomainException
     */
    public function confirm(int $batchId): void
    {
        if ($this->status !== EntryOrderStatus::DRAFT) {
            throw EntryOrderDomainException::invalidStateTransition($this->status->label(), EntryOrderStatus::AWAITING_DTE->label());
        }

        $this->batchId = $batchId;
        $this->status = EntryOrderStatus::AWAITING_DTE;
        $this->confirmedAt = new DateTimeImmutable();
    }

    /**
     * Records a DTE and the caravans it brought. The caravans were already created by the caller;
     * the order checks that they fit what it bought and moves on.
     *
     * @throws EntryOrderDomainException
     */
    public function recordDte(EntryOrderDteEntity $dte): void
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

        $pending = $this->pendingCount();

        if ($dte->getHeadCount() > $pending) {
            throw EntryOrderDomainException::invalid(
                "La orden es por {$this->troop->headCount} cabezas y quedan {$pending} por ingresar; "
                    . "el DTE trae {$dte->getHeadCount()}.",
                'HEAD_COUNT_EXCEEDED',
                'animals'
            );
        }

        if ($this->troop->sexComposition === SexComposition::MIXED) {
            $this->assertSexFits($dte, AnimalSex::MALE->value, (int) $this->troop->maleCount, 'machos');
            $this->assertSexFits($dte, AnimalSex::FEMALE->value, (int) $this->troop->femaleCount, 'hembras');
        }

        $this->dtes[] = $dte;

        if ($this->firstDteAt === null) {
            $this->firstDteAt = new DateTimeImmutable();
        }

        $this->status = $this->pendingCount() === 0 ? EntryOrderStatus::COMPLETED : EntryOrderStatus::PARTIAL;

        if ($this->status === EntryOrderStatus::COMPLETED) {
            $this->closedAt = new DateTimeImmutable();
        }
    }

    /**
     * No more DTEs will come: fewer head arrived than were bought. Only from PARTIAL.
     *
     * @throws EntryOrderDomainException
     */
    public function closeIncomplete(string $reason): void
    {
        if ($this->status !== EntryOrderStatus::PARTIAL) {
            throw EntryOrderDomainException::invalidStateTransition($this->status->label(), EntryOrderStatus::CLOSED_INCOMPLETE->label());
        }

        if (trim($reason) === '') {
            throw EntryOrderDomainException::reasonRequired('cerrar incompleta');
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
        if ($this->status !== EntryOrderStatus::DRAFT && $this->status !== EntryOrderStatus::AWAITING_DTE) {
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

    public function enteredCount(): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->getHeadCount(), $this->dtes));
    }

    public function enteredCountBySex(string $sex): int
    {
        return array_sum(array_map(fn (EntryOrderDteEntity $d) => $d->countBySex($sex), $this->dtes));
    }

    public function pendingCount(): int
    {
        return max(0, $this->troop->headCount - $this->enteredCount());
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

    public function isTroopReplaced(): bool
    {
        return $this->troopReplaced;
    }

    private function assertSexFits(EntryOrderDteEntity $dte, string $sex, int $declared, string $word): void
    {
        $after = $this->enteredCountBySex($sex) + $dte->countBySex($sex);

        if ($after > $declared) {
            throw EntryOrderDomainException::invalid(
                "La orden declara {$declared} {$word} y con este DTE serían {$after}.",
                'SEX_COUNT_EXCEEDED',
                'animals'
            );
        }
    }

    /**
     * @throws EntryOrderDomainException
     */
    private static function cleanBatchName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));

        if ($name === '') {
            throw EntryOrderDomainException::invalid(
                'El lote necesita un nombre. Si la compra es de una subasta, cargá la terminación y se sugiere solo.',
                'BATCH_NAME_MISSING',
                'batch_name'
            );
        }

        return $name;
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

    public function getBatchName(): string
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
