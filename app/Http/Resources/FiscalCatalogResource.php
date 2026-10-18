<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\FiscalDocumentTypeEntity;
use App\Core\Entities\TaxEntity;
use App\Core\Entities\TaxRateEntity;

/**
 * Shapes of the fixed fiscal catalog (DS15).
 */
final class FiscalCatalogResource
{
    /** @return array<string, mixed> */
    public static function type(FiscalDocumentTypeEntity $type): array
    {
        return [
            'id' => $type->id,
            'code' => $type->code,
            'name' => $type->name,
            'afip_code' => $type->afipCode,
            'kind' => $type->kind->value,
            'letter' => $type->letter,
            'discriminates_vat' => $type->discriminatesVat,
            'carries_amounts' => $type->carriesAmounts,
            'sign' => $type->sign,
            'requires_number' => $type->kind->requiresNumber(),
        ];
    }

    /** @return array<string, mixed> */
    public static function tax(TaxEntity $tax): array
    {
        return [
            'id' => $tax->id,
            'code' => $tax->code,
            'name' => $tax->name,
            'kind' => $tax->kind,
            'jurisdiction' => $tax->jurisdiction,
            'applies_to' => $tax->scope->value,
            'is_recoverable' => $tax->isRecoverable,
            'rates' => array_map(fn (TaxRateEntity $rate) => [
                'id' => $rate->id,
                'code' => $rate->code,
                'name' => $rate->name,
                'rate' => $rate->rate,
                'valid_from' => $rate->validFrom,
                'valid_to' => $rate->validTo,
            ], $tax->rates),
        ];
    }
}
