<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\SampleDestinationPlan;
use App\Core\ValueObjects\InstitutionMeta;
use App\Models\ExtractionActDetail;
use App\Models\LabReportDetail;

/**
 * ADR-42: the single place that writes a protocol's per-type detail row.
 *
 * Four use cases create protocols, and each used to spell out every column inline. With the data
 * split in two tables that would be four places to forget a detail row, so the write lives here
 * once. Always called inside the caller's transaction.
 */
final class PersistProtocolDetailsService
{
    /**
     * ADR-39: the act's institution and its declared plan.
     *
     * Called on creation and again at signature, which is why it upserts: the act is a draft until
     * the professional signs it, and up to that moment both fields may still change.
     */
    public function upsertActDetail(
        int $protocolId,
        ?InstitutionMeta $institution,
        SampleDestinationPlan $destinationPlan,
        ?string $dispatchNoteNumber = null,
        ?string $dispatchedAt = null
    ): void {
        ExtractionActDetail::updateOrCreate(
            ['protocol_id' => $protocolId],
            [
                'institution' => $institution?->jsonSerialize(),
                'destination_plan' => $destinationPlan->value,
                'dispatch_note_number' => $dispatchNoteNumber,
                'dispatched_at' => $dispatchedAt,
            ]
        );
    }

    /**
     * ADR-39 rev.: the destination declared when the box was dispatched.
     *
     * A method of its own rather than an argument on upsertActDetail(), on purpose: that one
     * rewrites the whole row and runs at signature, where this column has no value yet — adding
     * it there would mean signing an act silently wipes the destination a previous shipment
     * declared. Here a single column is updated on a row that already exists: an act that
     * declared TO_BE_DERIVED has a detail row by definition, which is why a missing row is
     * answered with false instead of being created out of nothing.
     *
     * Written through the model rather than through a mass update: Builder::update() bypasses the
     * casts, so the array would reach the driver unencoded, and hand-encoding it here would be a
     * second JSON spelling of the same column next to the one upsertActDetail() writes.
     *
     * @return bool whether there was a row to update
     */
    public function declareDestinationInstitution(int $protocolId, InstitutionMeta $institution): bool
    {
        $detail = ExtractionActDetail::query()->find($protocolId);

        if ($detail === null) {
            return false;
        }

        $detail->destination_institution = $institution->jsonSerialize();

        return $detail->save();
    }

    /**
     * ADR-42: a report's own data.
     *
     * Returns without writing when there is no institution to record. That is not a loophole: a
     * digitised historical report genuinely names no institution, and the alternative would be to
     * invent one. What the schema guarantees is that a detail row which EXISTS is complete — the
     * rule that every NEW report must name its institution belongs to the use case, because it is
     * a rule about new documents rather than about the table.
     */
    public function createReportDetail(
        int $protocolId,
        ?InstitutionMeta $reportingInstitution,
        ?InstitutionMeta $analysingInstitution,
        bool $isDerived
    ): void {
        if ($reportingInstitution === null) {
            return;
        }

        LabReportDetail::updateOrCreate(
            ['protocol_id' => $protocolId],
            [
                'reporting_institution' => $reportingInstitution->jsonSerialize(),
                'analysing_institution' => $analysingInstitution?->jsonSerialize(),
                'is_derived' => $isDerived,
            ]
        );
    }
}
