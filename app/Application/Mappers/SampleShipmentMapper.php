<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\SampleShipmentEntity;
use App\Core\ValueObjects\InstitutionMeta;
use App\Models\SampleShipment;
use DateTimeImmutable;

final class SampleShipmentMapper
{
    public static function toDomain(SampleShipment $model): SampleShipmentEntity
    {
        $samples = [];
        $actIds = [];
        $cited = false;

        if ($model->relationLoaded('samples')) {
            foreach ($model->samples as $sample) {
                $samples[] = [
                    'id' => (int) $sample->id,
                    'caravan_id' => (int) $sample->caravan_id,
                    'caravan_number' => $sample->relationLoaded('caravan') ? (string) $sample->caravan?->identification : null,
                    'extraction_act_id' => $sample->extraction_act_id !== null ? (int) $sample->extraction_act_id : null,
                    'sample_type' => (string) $sample->sample_type,
                    'tube_number' => $sample->tube_number,
                    'extracted_on' => $sample->extracted_on?->format('Y-m-d'),
                    'status' => (string) $sample->status,
                ];

                if ($sample->extraction_act_id !== null) {
                    $actIds[(int) $sample->extraction_act_id] = true;
                }

                // ADR-37: a resolved tube means a laboratory report already cited this box.
                if ($sample->diagnostic_protocol_id !== null) {
                    $cited = true;
                }
            }
        }

        return new SampleShipmentEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            shippedOn: new DateTimeImmutable((string) $model->shipped_on),
            institution: InstitutionMeta::fromArray((array) $model->institution),
            coldChainOk: (bool) $model->cold_chain_ok,
            declaredByVeterinarianId: (int) $model->declared_by_veterinarian_id,
            declaredByName: (string) $model->declared_by_name,
            declaredAt: new DateTimeImmutable((string) $model->declared_at),
            conditionNotes: $model->condition_notes,
            voidedAt: $model->voided_at ? new DateTimeImmutable((string) $model->voided_at) : null,
            voidReason: $model->void_reason,
            samples: $samples,
            actsCovered: count($actIds),
            citedByReport: $cited
        );
    }
}
