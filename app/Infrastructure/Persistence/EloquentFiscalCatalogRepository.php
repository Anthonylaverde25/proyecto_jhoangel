<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\FiscalCatalogMapper;
use App\Core\Entities\FiscalDocumentTypeEntity;
use App\Core\Entities\TaxEntity;
use App\Core\Interfaces\IFiscalCatalogRepository;
use App\Models\FiscalDocumentType;
use App\Models\Tax;
use App\Models\TaxRate;

class EloquentFiscalCatalogRepository implements IFiscalCatalogRepository
{
    public function documentTypes(bool $activeOnly): array
    {
        return FiscalDocumentType::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('display_order')
            ->get()
            ->map(fn (FiscalDocumentType $type) => FiscalCatalogMapper::typeToEntity($type))
            ->all();
    }

    public function findDocumentType(int $id): ?FiscalDocumentTypeEntity
    {
        $model = FiscalDocumentType::query()->find($id);

        return $model ? FiscalCatalogMapper::typeToEntity($model) : null;
    }

    public function taxes(?string $date = null): array
    {
        return Tax::query()
            ->where('is_active', true)
            ->with('rates')
            ->orderBy('display_order')
            ->get()
            ->map(function (Tax $tax) use ($date) {
                $rates = $date === null ? $tax->rates : $tax->rates->filter(
                    fn (TaxRate $rate) => FiscalCatalogMapper::rateToEntity($rate)->isValidOn($date)
                );

                return FiscalCatalogMapper::taxToEntity($tax, $rates);
            })
            ->all();
    }

    public function findTax(int $id): ?TaxEntity
    {
        $model = Tax::query()->with('rates')->find($id);

        return $model ? FiscalCatalogMapper::taxToEntity($model) : null;
    }
}
