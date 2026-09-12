<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use JsonSerializable;

/**
 * ADR-42: what accompanies a laboratory report and nothing else.
 *
 * `reportingInstitution` is never null here — the protocol always names the centre it is filed
 * from (ADR-29), and once these fields live in their own table the database itself enforces it
 * instead of the FormRequest being the only thing standing in the way.
 *
 * `analysingInstitution` is set only on a derivation the professional declared (ADR-31 rev.).
 *
 * `result_date` deliberately stays on the header: it drives the COALESCE ordering that keeps acts
 * and reports on one timeline, and the from/to filters. Moving it would buy a second NOT NULL at
 * the price of rewriting that, which is not a trade worth making.
 */
final class LabReportDetails implements JsonSerializable
{
    public function __construct(
        private readonly InstitutionMeta $reportingInstitution,
        private readonly ?InstitutionMeta $analysingInstitution = null,
        private readonly bool $isDerived = false
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            reportingInstitution: InstitutionMeta::fromArray(
                is_array($data['reporting_institution'] ?? null) ? $data['reporting_institution'] : []
            ),
            analysingInstitution: InstitutionMeta::fromNullableArray(
                is_array($data['analysing_institution'] ?? null) ? $data['analysing_institution'] : null
            ),
            isDerived: (bool) ($data['is_derived'] ?? false)
        );
    }

    public function getReportingInstitution(): InstitutionMeta
    {
        return $this->reportingInstitution;
    }

    public function getAnalysingInstitution(): ?InstitutionMeta
    {
        return $this->analysingInstitution;
    }

    public function isDerived(): bool
    {
        return $this->isDerived;
    }

    /**
     * Who actually ran the assay, in one question: the third party on a derivation, the
     * professional's own centre otherwise.
     */
    public function getAnalysingInstitutionOrOwn(): InstitutionMeta
    {
        return $this->analysingInstitution ?? $this->reportingInstitution;
    }

    /**
     * ADR-35 (rev.): a derived result is a transcription of somebody else's paper, and that paper
     * is the only thing holding it up.
     */
    public function requiresAnalysisAttachment(): bool
    {
        return $this->isDerived;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'reporting_institution' => $this->reportingInstitution->jsonSerialize(),
            'analysing_institution' => $this->analysingInstitution?->jsonSerialize(),
            'is_derived' => $this->isDerived,
        ];
    }
}
