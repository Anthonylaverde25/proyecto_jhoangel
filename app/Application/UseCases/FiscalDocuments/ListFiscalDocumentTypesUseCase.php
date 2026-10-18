<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Core\Interfaces\IFiscalCatalogRepository;

/**
 * The provider document types that can be loaded now (DS22).
 */
final class ListFiscalDocumentTypesUseCase
{
    public function __construct(
        private readonly IFiscalCatalogRepository $catalog
    ) {
    }

    /** @return list<\App\Core\Entities\FiscalDocumentTypeEntity> */
    public function __invoke(): array
    {
        return $this->catalog->documentTypes(true);
    }
}
