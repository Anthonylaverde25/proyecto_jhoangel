<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Core\Interfaces\IFiscalCatalogRepository;

/**
 * Active taxes with the rates in force on a date (the document's issue date).
 */
final class ListTaxesUseCase
{
    public function __construct(
        private readonly IFiscalCatalogRepository $catalog
    ) {
    }

    /** @return list<\App\Core\Entities\TaxEntity> */
    public function __invoke(string $date): array
    {
        return $this->catalog->taxes($date);
    }
}
