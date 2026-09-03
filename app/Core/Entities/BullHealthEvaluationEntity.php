<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ReproductiveAptitudeStatus;
use DateTimeImmutable;

final class BullHealthEvaluationEntity
{
    /**
     * @param array<VeterinaryDiagnosisEntity> $activeDiagnoses
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $caravanId,
        private readonly ?DateTimeImmutable $lastEvaluationDate,
        private readonly ?string $aplomoNotes,
        private readonly ?float $scrotalCircumferenceCm,
        private readonly ?float $bodyConditionScore,
        private readonly string $libido = 'MEDIA',
        private ReproductiveAptitudeStatus $status = ReproductiveAptitudeStatus::PENDING_EVALUATION,
        private readonly ?string $observations = null,
        private readonly ?string $caravanNumber = null,
        private readonly array $activeDiagnoses = [],
        private readonly array $labSamples = []
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

    public function getLastEvaluationDate(): ?DateTimeImmutable
    {
        return $this->lastEvaluationDate;
    }

    public function getAplomoNotes(): ?string
    {
        return $this->aplomoNotes;
    }

    public function getScrotalCircumferenceCm(): ?float
    {
        return $this->scrotalCircumferenceCm;
    }

    public function getBodyConditionScore(): ?float
    {
        return $this->bodyConditionScore;
    }

    public function getLibido(): string
    {
        return $this->libido;
    }

    public function getStatus(): ReproductiveAptitudeStatus
    {
        return $this->status;
    }

    public function setStatus(ReproductiveAptitudeStatus $status): void
    {
        $this->status = $status;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function getCaravanNumber(): ?string
    {
        return $this->caravanNumber;
    }

    /**
     * @return array<VeterinaryDiagnosisEntity>
     */
    public function getActiveDiagnoses(): array
    {
        return $this->activeDiagnoses;
    }

    /**
     * @return array
     */
    public function getLabSamples(): array
    {
        return $this->labSamples;
    }

    public function isApt(): bool
    {
        return $this->status === ReproductiveAptitudeStatus::APT;
    }

    public function isUnderTreatment(): bool
    {
        return $this->status === ReproductiveAptitudeStatus::IN_TREATMENT;
    }

    public function isUnfit(): bool
    {
        return $this->status === ReproductiveAptitudeStatus::UNFIT;
    }
}
