<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ReceiptSheetStatus;
use App\Core\Enums\ReferenceMode;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryOrderDomainException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * An ING-03 receipt sheet: the paper one DTE of an entry order is received on at the chute, an
 * appendix of the order's ING-02. It has one blank line per head of the DTE still in transit when
 * it was issued, where the chute writes the caravan of each animal that arrives, plus a few free
 * lines for animals of more. It is identified on every page by order code, DTE, its number (R1,
 * R2…) and "Hoja N de M", so a loose page can be traced back. The system keeps which pages came
 * back scanned, and the head of the DTE it was issued for: if the DTE is corrected, it is outdated.
 *
 * How the animals are weighed is part of the paper — a weight per line, or one average in the
 * header — and so is how each line names its breed, coat and category — written in words, or by
 * the letter and number of the header's reference. Both are chosen when the sheet is issued and
 * can only change while it was not printed.
 */
final class EntryOrderReceiptSheetEntity
{
    /** Lines per printed page, the same as every other sheet. */
    public const ROWS_PER_PAGE = 20;

    /** Free lines after the head expected, for animals of more. */
    public const FREE_ROWS = 4;

    private bool $changed = false;

    /**
     * @param int[] $processedPages
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $number,
        private readonly ?int $dteId,
        private readonly string $dteNumber,
        private ReceiptSheetStatus $status,
        private readonly int $dteHeadCount,
        private readonly int $expectedHeadCount,
        private readonly int $rowCount,
        private readonly int $pageCount,
        private array $processedPages = [],
        private readonly ?int $issuedByUserId = null,
        private ?DateTimeInterface $printedAt = null,
        private ?DateTimeInterface $processedAt = null,
        private ?DateTimeInterface $replacedAt = null,
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly ?string $issuedByUserName = null,
        private WeighingMode $weighingMode = WeighingMode::INDIVIDUAL,
        private ReferenceMode $referenceMode = ReferenceMode::WRITTEN
    ) {
    }

    /**
     * One blank line per head of the DTE still to identify — in transit, or received by count
     * without caravan — and the free lines.
     */
    public static function issue(
        int $number,
        EntryOrderDteEntity $dte,
        ?int $userId,
        WeighingMode $weighingMode = WeighingMode::INDIVIDUAL,
        ReferenceMode $referenceMode = ReferenceMode::WRITTEN
    ): self {
        $rows = $dte->toIdentifyCount() + self::FREE_ROWS;

        return new self(
            id: null,
            number: $number,
            dteId: $dte->getId(),
            dteNumber: $dte->getDteNumber(),
            status: ReceiptSheetStatus::ISSUED,
            dteHeadCount: $dte->getHeadCount(),
            expectedHeadCount: $dte->toIdentifyCount(),
            rowCount: $rows,
            pageCount: self::pagesFor($rows),
            issuedByUserId: $userId,
            weighingMode: $weighingMode,
            referenceMode: $referenceMode
        );
    }

    public static function pagesFor(int $rows): int
    {
        return max(1, (int) ceil($rows / self::ROWS_PER_PAGE));
    }

    /**
     * Still out, but issued for another head count than the DTE declares now.
     */
    public function isOutdatedFor(EntryOrderDteEntity $dte): bool
    {
        return $this->status->isActive() && $this->dteHeadCount !== $dte->getHeadCount();
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

        $this->assertPaperCanChange('weighing_mode', 'cómo se pesa', 'pesar de otra forma');
        $this->weighingMode = $mode;
        $this->changed = true;
    }

    /**
     * Words or codes are other columns on the paper: like the weighing, only before it is printed.
     *
     * @throws EntryOrderDomainException
     */
    public function changeReferenceMode(ReferenceMode $mode): void
    {
        if ($mode === $this->referenceMode) {
            return;
        }

        $this->assertPaperCanChange('reference_mode', 'cómo se anota la raza y la categoría', 'anotarlas de otra forma');
        $this->referenceMode = $mode;
        $this->changed = true;
    }

    /**
     * @throws EntryOrderDomainException
     */
    private function assertPaperCanChange(string $field, string $what, string $otherwise): void
    {
        if (!$this->status->isActive()) {
            throw EntryOrderDomainException::invalid(
                "La hoja {$this->label()} está " . mb_strtolower($this->status->label()) . ": no se puede cambiar {$what}.",
                'RECEIPT_SHEET_NOT_ACTIVE',
                $field
            );
        }

        if ($this->printedAt !== null) {
            throw EntryOrderDomainException::invalid(
                "La hoja {$this->label()} ya se imprimió: para {$otherwise} emití una hoja nueva, que la reemplaza.",
                'RECEIPT_SHEET_ALREADY_PRINTED',
                $field
            );
        }
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

    public function getDteHeadCount(): int
    {
        return $this->dteHeadCount;
    }

    public function getExpectedHeadCount(): int
    {
        return $this->expectedHeadCount;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function getWeighingMode(): WeighingMode
    {
        return $this->weighingMode;
    }

    public function getReferenceMode(): ReferenceMode
    {
        return $this->referenceMode;
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
