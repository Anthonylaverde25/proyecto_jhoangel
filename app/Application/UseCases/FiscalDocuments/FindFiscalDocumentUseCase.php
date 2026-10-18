<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Interfaces\IFiscalDocumentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class FindFiscalDocumentUseCase
{
    public function __construct(
        private readonly IFiscalDocumentRepository $documents
    ) {
    }

    public function __invoke(int $id, int $companyId): FiscalDocumentEntity
    {
        return $this->documents->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Fiscal document {$id} not found.");
    }
}
