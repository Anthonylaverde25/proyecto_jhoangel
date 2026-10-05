<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ReceiptSheetStatus;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryOrderDomainException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * An ING-03 receipt sheet: the paper one DTE of an entry order is received on at the chute, an
 * appendix of the order's ING-02. It lists the caravans of the DTE still in transit when it was
 * issued, a few free lines for animals that arrive without being listed, and is identified on
 * every page by order code, DTE, its number (R1, R2…) and "Hoja N de M", so a loose page can be
 * traced back. The system keeps which pages came back scanned.
 *
 * How the animals are weighed is part of the paper — a weight per line, or one average in the
 * header — so it is chosen when the sheet is issued and can only change while it was not printed.
 */
final class EntryOrderReceiptSheetEntity
{
    /** Lines per printed page, the same as every other sheet. */
    public const ROWS_PER_PAGE = 20;

    /** Free lines after the listed caravans, for animals the DTE does not list. */
    public const FREE_ROWS = 4;

    private bool $changed = false;

    /**
     * @param int[] $caravanIds in print order
     * @param int[] $processedPages
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $number,
        private readonly ?int $dteId,
        private readonly string $dteNumber,
        private ReceiptSheetStatus $status,
        private readonly array $caravanIds,
        private readonly int $pageCount,
        private array $processedPages = [],
        private readonly ?int $issuedByUserId = null,
        private ?DateTimeInterface $printedAt = null,
        private ?DateTimeInterface $processedAt = null,
        private ?DateTimeInterface $replacedAt = null,
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly ?string $issuedByUserName = null,
        private WeighingMode $weighingMode = WeighingMode::INDIVIDUAL
    ) {
    }

    /**
     * @param int[] $caravanIds the caravans in transit of the DTE, in print order
     */
    public static function issue(int $number, EntryOrderDteEntity $dte, array $caravanIds, ?int $userId, WeighingMode $weighingMode = WeighingMode::INDIVIDUAL): self
    {
        return new self(
            id: null,
            number: $number,
            dteId: $dte->getId(),
            dteNumber: $dte->getDteNumber(),
            status: ReceiptSheetStatus::ISSUED,
            caravanIds: array_values($caravanIds),
            pageCount: self::pagesFor(count($caravanIds)),
            issuedByUserId: $userId,
            weighingMode: $weighingMode
        );
    }

    public static function pagesFor(int $caravans): int
    {
        return max(1, (int) ceil(($caravans + self::FREE_ROWS) / self::ROWS_PER_PAGE));
    }

    /** "R1". */
    public function label(): string
    {
        return "R{$this->number}";
    }

    public function replace(): void
    {
        $this->status = ReceiptSheetStatus::REPLACED;
        $this->replacedAt = new DateTimeImmutable();
        $this->changed = true;
    }

    /**
     * Another weighing is another paper: once it went out to the chute, the change is a new sheet.
     *
     * @throws EntryOrderDomainException
     */
    public function changeWeighingMode(WeighingMode $mode): void
    {
        if ($mode === $this->weighingMode) {
            return;
        }

        if (!$this->status->isActive()) {
            throw EntryOrderDomainException::invalid(
                "La hoja {$this->label()} está " . mb_strtolower($this->status->label()) . ': no se puede cambiar cómo se pesa.',
                'RECEIPT_SHEET_NOT_ACTIVE',
                'weighing_mode'
            );
        }

        if ($this->printedAt !== null) {
            throw EntryOrderDomainException::invalid(
                "La hoja {$this->label()} ya se imprimió: para pesar de otra forma emití una hoja nueva, que la reemplaza.",
                'RECEIPT_SHEET_ALREADY_PRINTED',
                'weighing_mode'
            );
        }

        $this->weighingMode = $mode;
        $this->changed = true;
    }

    public function markPrinted(): void
    {
        $this->printedAt = new DateTimeImmutable();
        $this->changed = true;
    }

    /**
     * The pages scanned and received. A replaced sheet that comes back filled in still counts:
     * what the paper says happened, happened; it is just not the sheet expected.
     *
     * @param int[] $pages
     *
     * @throws EntryOrderDomainException
     */
    public function process(array $pages): void
    {
        foreach ($pages as $page) {
            if ($page < 1 || $page > $this->pageCount) {
                throw EntryOrderDomainException::invalid(
                    "La hoja {$this->label()} tiene {$this->pageCount} " . ($this->pageCount === 1 ? 'página' : 'páginas') . ": no existe la hoja {$page}.",
                    'RECEIPT_SHEET_PAGE_INVALID',
                    'pages'
                );
            }
        }

        $this->processedPages = array_values(array_unique([...$this->processedPages, ...$pages]));
        sort($this->processedPages);

        if ($this->status !== ReceiptSheetStatus::REPLACED) {
            $complete = count($this->processedPages) >= $this->pageCount;
            $this->status = $complete ? ReceiptSheetStatus::PROCESSED : ReceiptSheetStatus::PARTIAL;
            $this->processedAt = $complete ? new DateTimeImmutable() : $this->processedAt;
        }

        $this->changed = true;
    }

    /**
     * @return int[]
     */
    public function missingPages(): array
    {
        return array_values(array_diff(range(1, $this->pageCount), $this->processedPages));
    }

    public function isChanged(): bool
    {
        return $this->changed;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getDteId(): ?int
    {
        return $this->dteId;
    }

    public function getDteNumber(): string
    {
        return $this->dteNumber;
    }

    public function getStatus(): ReceiptSheetStatus
    {
        return $this->status;
    }

    /**
     * @return int[]
     */
    public function getCaravanIds(): array
    {
        return $this->caravanIds;
    }

    public function getWeighingMode(): WeighingMode
    {
        return $this->weighingMode;
    }

    public function getPageCount(): int
    {
        return $this->pageCount;
    }

    /**
     * @return int[]
     */
    public function getProcessedPages(): array
    {
        return $this->processedPages;
    }

    public function getIssuedByUserId(): ?int
    {
        return $this->issuedByUserId;
    }

    public function getPrintedAt(): ?DateTimeInterface
    {
        return $this->printedAt;
    }

    public function getProcessedAt(): ?DateTimeInterface
    {
        return $this->processedAt;
    }

    public function getReplacedAt(): ?DateTimeInterface
    {
        return $this->replacedAt;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getIssuedByUserName(): ?string
    {
        return $this->issuedByUserName;
    }
}
