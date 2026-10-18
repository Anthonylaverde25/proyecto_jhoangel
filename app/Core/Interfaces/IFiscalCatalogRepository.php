<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\FiscalDocumentTypeEntity;
use App\Core\Entities\TaxEntity;

/**
 * Read-only access to the fixed fiscal catalog of the tenant (DS15). There is no write method on
 * purpose: types, taxes and rates change only through migrations.
 */
interface IFiscalCatalogRepository
{
    /** @return list<FiscalDocumentTypeEntity> */
    public function documentTypes(bool $activeOnly): array;

    public function findDocumentType(int $id): ?FiscalDocumentTypeEntity;

    /**
     * Active taxes with their rates; with a date, only the rates in force that day.
     *
     * @return list<TaxEntity>
     */
    public function taxes(?string $date = null): array;

    /** With all its rates. */
    public function findTax(int $id): ?TaxEntity;
}
