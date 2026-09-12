<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\DiagnosticProtocolEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DiagnosticProtocolEntity
 */
class DiagnosticProtocolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DiagnosticProtocolEntity $this */
        return [
            'id' => $this->getId(),
            'company_id' => $this->getCompanyId(),
            'protocol_number' => $this->getProtocolNumber(),

            // ADR-11: which of the two documents this row is, and how they chain together.
            'protocol_type' => $this->getProtocolType()->value,
            'protocol_type_label' => $this->getProtocolType()->label(),
            'parent_protocol_id' => $this->getParentProtocolId(),

            'sample_date' => $this->getSampleDate()->format('Y-m-d'),
            'result_date' => $this->getResultDate()?->format('Y-m-d'),

            // ADR-12: chain of custody paperwork.
            'dispatch_note_number' => $this->getDispatchNoteNumber(),
            'dispatched_at' => $this->getDispatchedAt()?->format('Y-m-d'),
            'source_channel' => $this->getSourceChannel()->value,
            'status' => $this->getStatus()->value,
            'verification_status' => $this->getVerificationStatus()->value,
            'veterinarian_id' => $this->getVeterinarianId(),
            'veterinarian_name' => $this->getVeterinarianName(),

            // ADR-8: the frozen signature, not a live join against the catalogue.
            'signed_at' => $this->getSignedAt()?->format('Y-m-d H:i:s'),
            'signed_license_number' => $this->getSignedLicenseNumber(),
            'signed_veterinarian_name' => $this->getSignedVeterinarianName(),
            // ADR-38: the signature attests a PERSON. Both CUITs travel frozen with it.
            'signed_cuit' => $this->getSignedCuit(),
            'signed_billing_cuit' => $this->getSignedBillingCuit(),
            'is_signed' => $this->isSigned(),
            'can_be_signed' => $this->canBeSigned(),
            'can_receive_lab_report' => $this->canReceiveLabReport(),

            // ADR-39: what the act declared at the chute. The plan is a suggestion for the
            // report dialog and a filter for the producer — never a rule (ADR-40).
            'act_institution' => $this->getActInstitution()?->jsonSerialize(),
            'destination_plan' => $this->getDestinationPlan()->value,
            'destination_plan_label' => $this->getDestinationPlan()->label(),
            // ADR-39 rev.: a dónde se declaró enviarlos, al despachar. No lo congela la firma.
            'destination_institution' => $this->getDestinationInstitution()?->jsonSerialize(),

            // ADR-29: the centre the professional reports from, on every report.
            'reporting_institution' => $this->getReportingInstitution()?->jsonSerialize(),
            // ADR-31 (rev.): the third party that ran the assay — only on a derivation.
            'analysing_institution' => $this->getAnalysingInstitution()?->jsonSerialize(),
            // ADR-31 (rev.): declared by the professional, never inferred from a CUIT.
            'is_derived' => $this->isDerived(),
            'requires_analysis_attachment' => $this->requiresAnalysisAttachment(),

            // ADR-30: where the tubes are, read from the tubes themselves.
            'shipped_samples_count' => $this->getShippedSamplesCount(),
            'unshipped_samples_count' => $this->getUnshippedSamplesCount(),
            'has_unshipped_samples' => $this->hasUnshippedSamples(),

            'observations' => $this->getObservations(),
            'created_by_user_id' => $this->getCreatedByUserId(),
            'voided_at' => $this->getVoidedAt()?->format('Y-m-d H:i:s'),
            'void_reason' => $this->getVoidReason(),

            'samples_count' => $this->getSamplesCount(),
            'pending_samples_count' => $this->getPendingSamplesCount(),
            'positive_findings_count' => $this->getPositiveFindingsCount(),
            'attachments' => ProtocolAttachmentResource::collection($this->getAttachments())->resolve($request),
            'lab_samples' => $this->getLabSamples(),
        ];
    }
}
