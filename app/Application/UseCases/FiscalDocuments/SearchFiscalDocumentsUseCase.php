<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Core\Interfaces\IFiscalDocumentRepository;
use App\Core\ValueObjects\FiscalDocumentFilters;

final class SearchFiscalDocumentsUseCase
{
    public function __construct(
        private readonly IFiscalDocumentRepository $documents
    ) {
    }

    /** @return array{data: list<\App\Core\Entities\FiscalDocumentEntity>, total: int} */
    public function __invoke(FiscalDocumentFilters $filters, int $companyId): array
    {
        return $this->documents->search($filters, $companyId);
    }
}
