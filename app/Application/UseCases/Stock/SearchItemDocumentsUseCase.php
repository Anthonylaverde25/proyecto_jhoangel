<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\ValueObjects\ItemDocumentFilters;

final class SearchItemDocumentsUseCase
{
    public function __construct(
        private readonly IItemDocumentRepository $documents
    ) {
    }

    /** @return array{data: list<\App\Core\Entities\ItemDocumentEntity>, total: int} */
    public function __invoke(ItemDocumentFilters $filters, int $companyId): array
    {
        return $this->documents->search($filters, $companyId);
    }
}
