<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * What a page of the light caravan list asks for.
 *
 * `batchId` is a batch id, the literal 'unassigned' (caravans in no batch), or null (any batch).
 * `sort` is 'identification' (default) or 'recent': latest arrivals to their batch first.
 */
final readonly class CaravanSummaryCriteria
{
    public const SORT_IDENTIFICATION = 'identification';
    public const SORT_RECENT = 'recent';
    // The /caravans inventory still loads its whole herd in one page (per_page=1000).
    public const MAX_PER_PAGE = 1000;

    public string $sort;
    public int $page;
    public int $perPage;
    public ?string $sex;

    public function __construct(
        public string $scope = 'own',
        public int|string|null $batchId = null,
        public ?string $search = null,
        ?string $sex = null,
        ?string $sort = null,
        int $page = 1,
        int $perPage = 25,
    ) {
        $sex = $sex !== null ? strtoupper($sex) : null;
        $this->sex = in_array($sex, ['M', 'H'], true) ? $sex : null;
        $this->sort = $sort === self::SORT_RECENT ? self::SORT_RECENT : self::SORT_IDENTIFICATION;
        $this->page = max(1, $page);
        $this->perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
    }

    public function isUnassigned(): bool
    {
        return $this->batchId === 'unassigned' || $this->batchId === 0 || $this->batchId === '0';
    }

    public function specificBatchId(): ?int
    {
        return is_numeric($this->batchId) && (int) $this->batchId > 0 ? (int) $this->batchId : null;
    }
}
