<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\DiagnosticSourceChannel;
use App\Core\Enums\ProtocolStatus;
use App\Core\Enums\ProtocolVerificationStatus;
use App\Core\Enums\SampleDestinationPlan;
use App\Core\ValueObjects\ExtractionActDetails;
use App\Core\ValueObjects\InstitutionMeta;
use App\Core\ValueObjects\LabReportDetails;
use DateTimeImmutable;

/**
 * The evidentiary document backing a diagnostic session. Aggregate root over its
 * attachments, laboratory determinations and derived clinical findings.
 */
final class DiagnosticProtocolEntity
{
    /**
     * @param array<ProtocolAttachmentEntity> $attachments
     * @param array<array<string, mixed>> $labSamples
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly string $protocolNumber,
        private readonly DateTimeImmutable $sampleDate,
        private readonly ?DateTimeImmutable $resultDate,
        private readonly DiagnosticSourceChannel $sourceChannel,
        private readonly ProtocolStatus $status = ProtocolStatus::DRAFT,
        private readonly ProtocolVerificationStatus $verificationStatus = ProtocolVerificationStatus::UNVERIFIED,
        private readonly ?int $veterinarianId = null,
        private readonly ?DateTimeImmutable $signedAt = null,
        private readonly ?int $signedByVeterinarianId = null,
        private readonly ?string $signedLicenseNumber = null,
        private readonly ?string $signedVeterinarianName = null,
        private readonly ?string $observations = null,
        private readonly ?int $createdByUserId = null,
        private readonly ?DateTimeImmutable $voidedAt = null,
        private readonly ?string $voidReason = null,
        private readonly ?string $veterinarianName = null,
        private readonly array $attachments = [],
        private readonly array $labSamples = [],
        private readonly int $samplesCount = 0,
        private readonly int $positiveFindingsCount = 0,
        private readonly DiagnosticProtocolType $protocolType = DiagnosticProtocolType::LAB_REPORT,
        private readonly ?int $parentProtocolId = null,

        // ADR-38: both CUITs of the signing professional, frozen. Read live from their file,
        // a later correction would change how an already closed report reads.
        private readonly ?string $signedCuit = null,
        private readonly ?string $signedBillingCuit = null,
        private readonly int $pendingSamplesCount = 0,
        // ADR-42: the per-type data. Exactly one of the two is ever set, decided by
        // $protocolType, and each carries a stable meaning of its own rather than six nullable
        // fields whose applicability the reader has to work out.
        private readonly ?ExtractionActDetails $actDetails = null,
        private readonly ?LabReportDetails $reportDetails = null,
        // ADR-30: counted from the tubes, never stored on the header.
        private readonly int $shippedSamplesCount = 0,
        private readonly int $unshippedSamplesCount = 0
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getProtocolNumber(): string
    {
        return $this->protocolNumber;
    }

    public function getSampleDate(): DateTimeImmutable
    {
        return $this->sampleDate;
    }

    /**
     * NULL on an EXTRACTION_ACT: at extraction time the laboratory has not spoken yet.
     */
    public function getResultDate(): ?DateTimeImmutable
    {
        return $this->resultDate;
    }

    public function getProtocolType(): DiagnosticProtocolType
    {
        return $this->protocolType;
    }

    public function getParentProtocolId(): ?int
    {
        return $this->parentProtocolId;
    }

    public function getDispatchNoteNumber(): ?string
    {
        return $this->actDetails?->getDispatchNoteNumber();
    }

    public function getDispatchedAt(): ?DateTimeImmutable
    {
        $dispatchedAt = $this->actDetails?->getDispatchedAt();

        return $dispatchedAt !== null ? new DateTimeImmutable($dispatchedAt) : null;
    }

    /**
     * ADR-39: the centre the professional was working with when the samples were drawn.
     *
     * Optional — a field sampling may have no institution behind it — and frozen by the signature.
     */
    public function getActInstitution(): ?InstitutionMeta
    {
        return $this->actDetails?->getInstitution();
    }

    /**
     * ADR-40: what the professional INTENDED to do with the tubes. A plan, never a rule.
     *
     * Nothing validates against this. An act that says IN_SITU whose tubes ended up derived is not
     * wrong: the intention was real when it was declared, and the fact lives on the lab report.
     */
    public function getDestinationPlan(): SampleDestinationPlan
    {
        return $this->actDetails?->getDestinationPlan() ?? SampleDestinationPlan::default();
    }

    /**
     * ADR-39 rev.: la institución a la que el profesional declaró haber enviado los tubos.
     *
     * Null cuando todavía no se despachó nada, cuando el acta no se deriva, y en toda acta
     * anterior a esta columna. A diferencia de getActInstitution(), esto NO lo congela la firma:
     * se declara al despachar, que siempre ocurre después de firmar.
     */
    public function getDestinationInstitution(): ?InstitutionMeta
    {
        return $this->actDetails?->getDestinationInstitution();
    }

    public function getExtractionActDetails(): ?ExtractionActDetails
    {
        return $this->actDetails;
    }

    public function getLabReportDetails(): ?LabReportDetails
    {
        return $this->reportDetails;
    }



    public function getPendingSamplesCount(): int
    {
        return $this->pendingSamplesCount;
    }

    public function getSourceChannel(): DiagnosticSourceChannel
    {
        return $this->sourceChannel;
    }

    public function getStatus(): ProtocolStatus
    {
        return $this->status;
    }

    public function getVerificationStatus(): ProtocolVerificationStatus
    {
        return $this->verificationStatus;
    }

    public function getVeterinarianId(): ?int
    {
        return $this->veterinarianId;
    }


    public function getSignedAt(): ?DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function getSignedByVeterinarianId(): ?int
    {
        return $this->signedByVeterinarianId;
    }

    public function getSignedLicenseNumber(): ?string
    {
        return $this->signedLicenseNumber;
    }

    public function getSignedVeterinarianName(): ?string
    {
        return $this->signedVeterinarianName;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function getCreatedByUserId(): ?int
    {
        return $this->createdByUserId;
    }

    public function getVoidedAt(): ?DateTimeImmutable
    {
        return $this->voidedAt;
    }

    public function getVoidReason(): ?string
    {
        return $this->voidReason;
    }

    public function getVeterinarianName(): ?string
    {
        return $this->veterinarianName;
    }


    /**
     * @return array<ProtocolAttachmentEntity>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getLabSamples(): array
    {
        return $this->labSamples;
    }

    public function getSamplesCount(): int
    {
        return $this->samplesCount;
    }

    public function getPositiveFindingsCount(): int
    {
        return $this->positiveFindingsCount;
    }

    public function isSigned(): bool
    {
        return $this->signedAt !== null;
    }

    public function isVoided(): bool
    {
        return $this->status === ProtocolStatus::VOIDED;
    }

    public function countsForAptitude(): bool
    {
        return $this->status->countsForAptitude();
    }

    /**
     * ADR-13: a signature is a professional act performed once, on a draft. Re-signing a
     * confirmed act, or signing something already voided, is never legitimate.
     */
    public function canBeSigned(): bool
    {
        return $this->protocolType->isAct()
            && $this->status === ProtocolStatus::DRAFT
            && $this->signedAt === null;
    }

    /**
     * ADR-11: the laboratory reports on tubes whose chain of custody is closed — which now means
     * one thing only: the professional signed for them.
     *
     * v7 also demanded a registered arrival. That gate is gone with the concept behind it: the
     * samples may never have left the professional's hands at all (ADR-30), and demanding a
     * declaration of arrival for a tube processed in house asked for a fact nobody witnessed.
     */
    public function canReceiveLabReport(): bool
    {
        return $this->protocolType->isAct()
            && $this->status === ProtocolStatus::CONFIRMED
            && $this->signedAt !== null;
    }

    public function getSignedCuit(): ?string
    {
        return $this->signedCuit;
    }

    public function getSignedBillingCuit(): ?string
    {
        return $this->signedBillingCuit;
    }

    public function getAnalysingInstitution(): ?InstitutionMeta
    {
        return $this->reportDetails?->getAnalysingInstitution();
    }

    public function getShippedSamplesCount(): int
    {
        return $this->shippedSamplesCount;
    }

    public function getUnshippedSamplesCount(): int
    {
        return $this->unshippedSamplesCount;
    }

    /** ADR-30: tubes still in the professional's hands, awaiting dispatch or processed there. */
    public function hasUnshippedSamples(): bool
    {
        return $this->unshippedSamplesCount > 0;
    }

    public function getReportingInstitution(): ?InstitutionMeta
    {
        return $this->reportDetails?->getReportingInstitution();
    }

    /**
     * Who actually ran the assay, in one question.
     *
     * On a derivation that is the third party; otherwise it is the professional's own centre.
     */
    public function getAnalysingInstitutionOrOwn(): ?InstitutionMeta
    {
        return $this->reportDetails?->getAnalysingInstitutionOrOwn();
    }

    /**
     * ADR-31 (rev.): the professional declared that these samples were processed somewhere else.
     *
     * It is a statement, not an inference. The institution meta says WHO processed the samples,
     * in house or not; this says HOW they got there.
     */
    public function isDerived(): bool
    {
        return $this->reportDetails?->isDerived() ?? false;
    }

    /**
     * ADR-35 (rev.): a derived result is a transcription of somebody else's paper, and that paper
     * is the only thing holding it up.
     */
    public function requiresAnalysisAttachment(): bool
    {
        return $this->reportDetails?->requiresAnalysisAttachment() ?? false;
    }

    public function isExtractionAct(): bool
    {
        return $this->protocolType->isAct();
    }

    public function hasPendingSamples(): bool
    {
        return $this->pendingSamplesCount > 0;
    }
}
