<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\ValueObjects\InstitutionMeta;
use DateTimeImmutable;

/**
 * ADR-30: one dispatch of tubes, declared by the professional who made it.
 *
 * The predecessor of this concept asked the veterinarian to register an ARRIVAL — a fact they
 * did not witness, since they are the one handing the box over. Modelling what the declarant
 * actually saw is what removes the whole COUNTERPARTY / SELF_DECLARED distinction: there is
 * nothing to tell apart when every declaration comes from the person who made the trip.
 */
final class SampleShipmentEntity
{
    /**
     * @param array<array<string, mixed>> $samples Tubes that travelled in this box.
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly DateTimeImmutable $shippedOn,
        private readonly InstitutionMeta $institution,
        private readonly bool $coldChainOk,
        private readonly int $declaredByVeterinarianId,
        private readonly string $declaredByName,
        private readonly DateTimeImmutable $declaredAt,
        private readonly ?string $conditionNotes = null,
        private readonly ?DateTimeImmutable $voidedAt = null,
        private readonly ?string $voidReason = null,
        private readonly array $samples = [],
        private readonly int $actsCovered = 0,
        private readonly bool $citedByReport = false
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

    public function getShippedOn(): DateTimeImmutable
    {
        return $this->shippedOn;
    }

    public function getInstitution(): InstitutionMeta
    {
        return $this->institution;
    }

    public function isColdChainOk(): bool
    {
        return $this->coldChainOk;
    }

    public function getConditionNotes(): ?string
    {
        return $this->conditionNotes;
    }

    public function getDeclaredByVeterinarianId(): int
    {
        return $this->declaredByVeterinarianId;
    }

    public function getDeclaredByName(): string
    {
        return $this->declaredByName;
    }

    public function getDeclaredAt(): DateTimeImmutable
    {
        return $this->declaredAt;
    }

    public function getVoidedAt(): ?DateTimeImmutable
    {
        return $this->voidedAt;
    }

    public function getVoidReason(): ?string
    {
        return $this->voidReason;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getSamples(): array
    {
        return $this->samples;
    }

    public function getSamplesCount(): int
    {
        return count($this->samples);
    }

    /** ADR-36: one cooler may carry tubes from several chute sessions. */
    public function getActsCovered(): int
    {
        return $this->actsCovered;
    }

    public function isVoided(): bool
    {
        return $this->voidedAt !== null;
    }

    /**
     * ADR-37: once a laboratory report resolved any of these tubes, somebody relied on what this
     * document says. From then on it is corrected by voiding and reissuing, never in place.
     */
    public function isCitedByReport(): bool
    {
        return $this->citedByReport;
    }

    public function isEditable(): bool
    {
        return !$this->isVoided() && !$this->citedByReport;
    }
}
