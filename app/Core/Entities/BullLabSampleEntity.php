<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\LabSampleStatus;
use App\Core\Enums\SampleType;
use DateTimeImmutable;

/**
 * A single laboratory determination. ADR-1: this is the source of truth for results,
 * negative and positive alike; `veterinary_diagnoses` only holds derived positive findings.
 */
final class BullLabSampleEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $caravanId,
        private readonly SampleType $sampleType,
        private readonly int $sampleRound,
        private readonly DateTimeImmutable $sampleDate,
        private readonly LabSampleStatus $status,
        private readonly ?int $diagnosticProtocolId = null,
        private readonly ?int $veterinarianId = null,
        private readonly ?int $pathogenId = null,
        private readonly ?string $tubeNumber = null,
        private readonly ?DateTimeImmutable $resultDate = null,
        private readonly ?string $notes = null,
        private readonly ?string $pathogenCode = null,
        private readonly ?string $caravanNumber = null
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

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getSampleType(): SampleType
    {
        return $this->sampleType;
    }

    public function getSampleRound(): int
    {
        return $this->sampleRound;
    }

    public function getSampleDate(): DateTimeImmutable
    {
        return $this->sampleDate;
    }

    public function getStatus(): LabSampleStatus
    {
        return $this->status;
    }

    public function getDiagnosticProtocolId(): ?int
    {
        return $this->diagnosticProtocolId;
    }

    public function getVeterinarianId(): ?int
    {
        return $this->veterinarianId;
    }

    public function getPathogenId(): ?int
    {
        return $this->pathogenId;
    }

    public function getTubeNumber(): ?string
    {
        return $this->tubeNumber;
    }

    public function getResultDate(): ?DateTimeImmutable
    {
        return $this->resultDate;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getPathogenCode(): ?string
    {
        return $this->pathogenCode;
    }

    public function getCaravanNumber(): ?string
    {
        return $this->caravanNumber;
    }

    public function isPositive(): bool
    {
        return $this->status === LabSampleStatus::POSITIVE_DETECTED;
    }
}
