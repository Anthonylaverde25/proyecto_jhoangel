<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

final class FiscalCatalogUseCases
{
    public function __construct(
        public readonly ListFiscalDocumentTypesUseCase $documentTypes,
        public readonly ListTaxesUseCase $taxes,
    ) {
    }
}
