<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\FiscalDocumentTypeEntity;
use App\Core\Entities\TaxEntity;
use App\Core\Entities\TaxRateEntity;
use App\Core\Enums\FiscalDocumentKind;
use App\Core\Enums\TaxScope;
use App\Models\FiscalDocumentType;
use App\Models\Tax;
use App\Models\TaxRate;

final class FiscalCatalogMapper
{
    public static function typeToEntity(FiscalDocumentType $model): FiscalDocumentTypeEntity
    {
        return new FiscalDocumentTypeEntity(
            (int) $model->id,
            $model->code,
            $model->name,
            $model->afip_code,
            FiscalDocumentKind::from($model->kind),
            $model->letter,
            (bool) $model->discriminates_vat,
            (bool) $model->carries_amounts,
            (int) $model->sign,
            (bool) $model->is_active,
        );
    }

    /** @param iterable<TaxRate>|null $rates the rates to keep; all loaded ones when null */
    public static function taxToEntity(Tax $model, ?iterable $rates = null): TaxEntity
    {
        $rateEntities = [];
        foreach ($rates ?? ($model->relationLoaded('rates') ? $model->rates : []) as $rate) {
            $rateEntities[] = self::rateToEntity($rate);
        }

        return new TaxEntity(
            (int) $model->id,
            $model->code,
            $model->name,
            $model->kind,
            $model->jurisdiction,
            TaxScope::from($model->applies_to),
            (bool) $model->is_recoverable,
            (bool) $model->is_active,
            $rateEntities,
        );
    }

    public static function rateToEntity(TaxRate $model): TaxRateEntity
    {
        return new TaxRateEntity(
            (int) $model->id,
            (int) $model->tax_id,
            $model->code,
            $model->name,
            DecimalColumn::read($model->rate, 4),
            substr((string) $model->valid_from, 0, 10),
            $model->valid_to === null ? null : substr((string) $model->valid_to, 0, 10),
        );
    }
}
