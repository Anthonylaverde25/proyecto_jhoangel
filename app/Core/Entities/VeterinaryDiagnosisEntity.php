<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\DiagnosisStatus;
use DateTimeImmutable;
use DateTimeInterface;

final class VeterinaryDiagnosisEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $caravanId,
        private readonly int $pathogenId,
        private readonly ?int $veterinarianId,
        private readonly DateTimeImmutable $diagnosisDate,
        private DiagnosisStatus $status,
        private ?DateTimeImmutable $resolutionDate = null,
        private ?string $treatmentNotes = null,
        private string $sourceContext = 'PRE_SERVICE',
        private ?string $pathogenCode = null,
        private ?string $pathogenName = null,
        private ?bool $pathogenIsDisqualifying = null,
        private ?string $veterinarianName = null
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

    public function getPathogenId(): int
    {
        return $this->pathogenId;
    }

    public function getVeterinarianId(): ?int
    {
        return $this->veterinarianId;
    }

    public function getDiagnosisDate(): DateTimeImmutable
    {
        return $this->diagnosisDate;
    }

    public function getStatus(): DiagnosisStatus
    {
        return $this->status;
    }

    public function getResolutionDate(): ?DateTimeImmutable
    {
        return $this->resolutionDate;
    }

    public function getTreatmentNotes(): ?string
    {
        return $this->treatmentNotes;
    }

    public function getSourceContext(): string
    {
        return $this->sourceContext;
    }

    public function getPathogenCode(): ?string
    {
        return $this->pathogenCode;
    }

    public function getPathogenName(): ?string
    {
        return $this->pathogenName;
    }

    public function isPathogenDisqualifying(): bool
    {
        return (bool) $this->pathogenIsDisqualifying;
    }

    public function getVeterinarianName(): ?string
    {
        return $this->veterinarianName;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isInTreatment(): bool
    {
        return $this->status === DiagnosisStatus::IN_TREATMENT;
    }

    public function resolve(DateTimeImmutable $resolutionDate, ?string $additionalNotes = null): void
    {
        $this->status = DiagnosisStatus::RESOLVED;
        $this->resolutionDate = $resolutionDate;
        if ($additionalNotes) {
            $this->treatmentNotes = trim(($this->treatmentNotes ?? '') . "\n[Alta]: " . $additionalNotes);
        }
    }
}
