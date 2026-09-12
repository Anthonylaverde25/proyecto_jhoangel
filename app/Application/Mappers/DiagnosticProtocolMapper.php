<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\DiagnosticSourceChannel;
use App\Core\Enums\ProtocolStatus;
use App\Core\Enums\ProtocolVerificationStatus;
use App\Core\Enums\SampleDestinationPlan;
use App\Core\ValueObjects\ExtractionActDetails;
use App\Core\ValueObjects\InstitutionMeta;
use App\Core\ValueObjects\LabReportDetails;
use App\Models\DiagnosticProtocol;
use DateTimeImmutable;

final class DiagnosticProtocolMapper
{
    public static function toDomain(DiagnosticProtocol $model): DiagnosticProtocolEntity
    {
        $veterinarian = $model->relationLoaded('veterinarian') ? $model->veterinarian : null;

        $attachments = [];
        if ($model->relationLoaded('attachments')) {
            foreach ($model->attachments as $attachment) {
                $attachments[] = ProtocolAttachmentMapper::toDomain($attachment);
            }
        }

        $labSamples = [];
        $positiveFindings = 0;
        $pendingSamples = 0;
        // ADR-30: read from the tubes, never stored on the header. NULL shipment means the
        // tube is still in the professional's hands.
        $shippedSamples = 0;
        $unshippedSamples = 0;
        // An extraction act owns its tubes through `drawnSamples` (extraction_act_id); a
        // laboratory report owns the ones it RESOLVED through `labSamples`. The document type
        // decides, not whichever relation happened to be eager loaded.
        $isAct = (string) ($model->protocol_type ?? DiagnosticProtocolType::LAB_REPORT->value)
            === DiagnosticProtocolType::EXTRACTION_ACT->value;

        $sampleRelation = $isAct
            ? ($model->relationLoaded('drawnSamples') ? $model->drawnSamples : null)
            : ($model->relationLoaded('labSamples') ? $model->labSamples : null);

        if ($sampleRelation !== null) {
            foreach ($sampleRelation as $sample) {
                $labSamples[] = [
                    'id' => (int) $sample->id,
                    'caravan_id' => (int) $sample->caravan_id,
                    'caravan_number' => $sample->relationLoaded('caravan') ? (string) $sample->caravan?->identification : null,
                    'pathogen_id' => $sample->pathogen_id !== null ? (int) $sample->pathogen_id : null,
                    'pathogen_code' => $sample->relationLoaded('pathogen') ? $sample->pathogen?->code : null,
                    'pathogen_name' => $sample->relationLoaded('pathogen') ? $sample->pathogen?->name : null,
                    'sample_type' => (string) $sample->sample_type,
                    'sample_round' => (int) $sample->sample_round,
                    'sample_date' => $sample->sample_date?->format('Y-m-d'),
                    'result_date' => $sample->result_date?->format('Y-m-d'),
                    'tube_number' => $sample->tube_number,
                    'status' => (string) $sample->status,
                    // ADR-26: the tube carries its own extraction date; the act's `sample_date`
                    // is only the day the document was opened.
                    'extracted_on' => $sample->extracted_on?->format('Y-m-d'),
                    // ADR-30: NULL means it never left the professional's hands.
                    'sample_shipment_id' => $sample->sample_shipment_id !== null
                        ? (int) $sample->sample_shipment_id
                        : null,
                    'notes' => $sample->notes,
                ];

                if ($sample->sample_shipment_id !== null) {
                    $shippedSamples++;
                } else {
                    $unshippedSamples++;
                }

                if ((string) $sample->status === 'POSITIVE_DETECTED') {
                    $positiveFindings++;
                }

                if ((string) $sample->status === 'PENDING_RESULTS') {
                    $pendingSamples++;
                }
            }
        }

        return new DiagnosticProtocolEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            protocolNumber: (string) $model->protocol_number,
            sampleDate: new DateTimeImmutable((string) $model->sample_date),
            // NULL on an extraction act: the laboratory has not reported yet.
            resultDate: $model->result_date !== null ? new DateTimeImmutable((string) $model->result_date) : null,
            sourceChannel: DiagnosticSourceChannel::from((string) $model->source_channel),
            status: ProtocolStatus::from((string) $model->status),
            verificationStatus: ProtocolVerificationStatus::from((string) $model->verification_status),
            veterinarianId: $model->veterinarian_id !== null ? (int) $model->veterinarian_id : null,
            signedAt: $model->signed_at ? new DateTimeImmutable((string) $model->signed_at) : null,
            signedByVeterinarianId: $model->signed_by_veterinarian_id !== null ? (int) $model->signed_by_veterinarian_id : null,
            signedLicenseNumber: $model->signed_license_number,
            signedVeterinarianName: $model->signed_veterinarian_name,
            observations: $model->observations,
            createdByUserId: $model->created_by_user_id !== null ? (int) $model->created_by_user_id : null,
            voidedAt: $model->voided_at ? new DateTimeImmutable((string) $model->voided_at) : null,
            voidReason: $model->void_reason,
            veterinarianName: $veterinarian?->name,
            attachments: $attachments,
            labSamples: $labSamples,
            samplesCount: count($labSamples),
            positiveFindingsCount: $positiveFindings,
            protocolType: DiagnosticProtocolType::from((string) ($model->protocol_type ?? DiagnosticProtocolType::LAB_REPORT->value)),
            parentProtocolId: $model->parent_protocol_id !== null ? (int) $model->parent_protocol_id : null,
            signedCuit: $model->signed_cuit,
            signedBillingCuit: $model->signed_billing_cuit,
            pendingSamplesCount: $pendingSamples,
            actDetails: self::actDetails($model),
            reportDetails: self::reportDetails($model),
            shippedSamplesCount: $shippedSamples,
            unshippedSamplesCount: $unshippedSamples
        );
    }

    /**
     * ADR-39 / ADR-42: an act's own data.
     *
     * An act with no detail row — every act drawn before ADR-39, and any row a migration has not
     * reached yet — reads as "nothing declared", which is true rather than absent.
     */
    private static function actDetails(DiagnosticProtocol $model): ?ExtractionActDetails
    {
        if (!$model->protocol_type || $model->protocol_type !== DiagnosticProtocolType::EXTRACTION_ACT->value) {
            return null;
        }

        $detail = $model->extractionActDetail;

        return new ExtractionActDetails(
            institution: InstitutionMeta::fromNullableArray($detail?->institution),
            destinationPlan: SampleDestinationPlan::fromNullable($detail?->destination_plan),
            dispatchNoteNumber: $detail?->dispatch_note_number,
            dispatchedAt: self::asDateString($detail?->dispatched_at),
            destinationInstitution: InstitutionMeta::fromNullableArray($detail?->destination_institution)
        );
    }

    /**
     * ADR-42: a report's own data. Null when the report names no institution at all, which after
     * migration V the schema makes impossible and before it only legacy rows can be.
     */
    private static function reportDetails(DiagnosticProtocol $model): ?LabReportDetails
    {
        if (($model->protocol_type ?? null) === DiagnosticProtocolType::EXTRACTION_ACT->value) {
            return null;
        }

        $detail = $model->labReportDetail;

        $reporting = InstitutionMeta::fromNullableArray($detail?->reporting_institution);

        if ($reporting === null) {
            return null;
        }

        return new LabReportDetails(
            reportingInstitution: $reporting,
            analysingInstitution: InstitutionMeta::fromNullableArray($detail?->analysing_institution),
            isDerived: (bool) ($detail->is_derived ?? false)
        );
    }

    private static function asDateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : (string) $value;
    }
}
