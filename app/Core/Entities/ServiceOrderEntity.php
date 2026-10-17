<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\ValueObjects\ServiceOrderDiagnosisSummary;
use DateTimeImmutable;
use DateTimeInterface;

final class ServiceOrderEntity
{
    /** Days after the bull withdrawal from which a pregnancy is reliably detected at the chute. */
    public const SUGGESTED_DIAGNOSIS_DELAY_DAYS = 45;

    /**
     * @param int[] $maleCaravanIds
     * @param int[] $femaleCaravanIds
     * @param ServiceOrderHistoryEntity[] $history
     * @param array<int, array{female_caravan_id: int, exposed_from: ?string, exposed_until: ?string, exit_reason: ?string, joined_after_start: bool, reproductive_status?: ?string, diagnosed_at?: ?string}> $femaleExposures
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private int $batchId,
        private readonly string $code,
        private ServiceOrderStatus $status,
        private string $plannedStartDate,
        private ?int $requestedByUserId = null,
        private ?int $reviewedByUserId = null,
        private ?int $approvedByUserId = null,
        private ?DateTimeInterface $reviewedAt = null,
        private ?DateTimeInterface $approvedAt = null,
        private ?DateTimeInterface $executedAt = null,
        private ?string $actualStartDate = null,
        private ?string $actualEndDate = null,
        private ?string $observations = null,
        private ?string $rejectionReason = null,
        private array $maleCaravanIds = [],
        private array $femaleCaravanIds = [],
        private array $history = [],
        private ?DateTimeInterface $createdAt = null,
        private ?DateTimeInterface $updatedAt = null,
        private string $serviceType = 'single',
        private bool $isControlledService = false,
        private array $femaleSireAssignments = [],
        private ?float $targetBullRatio = 3.0,
        private ?string $plannedEndDate = null,
        private ?float $finalPregnancyRate = null,
        private array $maleDetails = [],
        private array $bullReplacements = [],
        private array $allMaleCaravanIds = [],
        private array $femaleExposures = [],
        private ?string $batchName = null,
        private ?string $diagnosisCompletedAt = null,
        private ?int $parentServiceOrderId = null
    ) {
        if (empty($this->allMaleCaravanIds)) {
            $this->allMaleCaravanIds = $this->maleCaravanIds;
        }
    }

    public function getMaleDetails(): array
    {
        return $this->maleDetails;
    }

    public function getBullReplacements(): array
    {
        return $this->bullReplacements;
    }

    /**
     * Exposure window of every female of the order, keyed in list order.
     *
     * @return array<int, array{female_caravan_id: int, exposed_from: ?string, exposed_until: ?string, exit_reason: ?string, joined_after_start: bool}>
     */
    public function getFemaleExposures(): array
    {
        return $this->femaleExposures;
    }

    /**
     * Where the pregnancy diagnosis stands, from the current verdict of every female.
     *
     * @param string|null $exposureEndsOn the day the open exposures are about to end (closing day)
     */
    public function getDiagnosisSummary(?string $exposureEndsOn = null): ServiceOrderDiagnosisSummary
    {
        return ServiceOrderDiagnosisSummary::fromFemales($this->femaleExposures, $exposureEndsOn);
    }

    public function getDiagnosisCompletedAt(): ?string
    {
        return $this->diagnosisCompletedAt;
    }

    public function getParentServiceOrderId(): ?int
    {
        return $this->parentServiceOrderId;
    }

    public function acceptsDiagnosis(): bool
    {
        return $this->status->acceptsDiagnosis();
    }

    /**
     * Suggested first day for the diagnosis: 45 days after the bulls left. Informative only.
     */
    public function getSuggestedDiagnosisDate(): ?string
    {
        if ($this->actualEndDate === null || $this->status === ServiceOrderStatus::APPROVED) {
            return null;
        }

        return (new DateTimeImmutable(substr($this->actualEndDate, 0, 10)))
            ->modify('+' . self::SUGGESTED_DIAGNOSIS_DELAY_DAYS . ' days')
            ->format('Y-m-d');
    }

    public function getTargetBullRatio(): ?float
    {
        return $this->targetBullRatio;
    }

    public function getPlannedEndDate(): ?string
    {
        return $this->plannedEndDate;
    }

    public function getFinalPregnancyRate(): ?float
    {
        return $this->finalPregnancyRate;
    }

    public function getServiceType(): string
    {
        return $this->serviceType;
    }

    public function isControlledService(): bool
    {
        return $this->isControlledService;
    }

    /**
     * @return array
     */
    public function getFemaleSireAssignments(): array
    {
        return $this->femaleSireAssignments;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getBatchId(): int
    {
        return $this->batchId;
    }

    public function getBatchName(): ?string
    {
        return $this->batchName;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getStatus(): ServiceOrderStatus
    {
        return $this->status;
    }

    public function getRequestedByUserId(): ?int
    {
        return $this->requestedByUserId;
    }

    public function getReviewedByUserId(): ?int
    {
        return $this->reviewedByUserId;
    }

    public function getApprovedByUserId(): ?int
    {
        return $this->approvedByUserId;
    }

    public function getReviewedAt(): ?DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function getApprovedAt(): ?DateTimeInterface
    {
        return $this->approvedAt;
    }

    public function getExecutedAt(): ?DateTimeInterface
    {
        return $this->executedAt;
    }

    public function getPlannedStartDate(): string
    {
        return $this->plannedStartDate;
    }

    public function getActualStartDate(): ?string
    {
        return $this->actualStartDate;
    }

    public function getActualEndDate(): ?string
    {
        return $this->actualEndDate;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    /**
     * Returns only the active bulls currently serving in this order.
     *
     * @return int[]
     */
    public function getMaleCaravanIds(): array
    {
        return $this->getActiveMaleCaravanIds();
    }

    /**
     * @return int[]
     */
    public function getActiveMaleCaravanIds(): array
    {
        if (empty($this->maleDetails)) {
            return $this->maleCaravanIds;
        }

        $active = [];
        foreach ($this->maleDetails as $d) {
            if (($d['status'] ?? 'ACTIVE') === 'ACTIVE') {
                $active[] = (int) $d['male_caravan_id'];
            }
        }

        return $active;
    }

    /**
     * Returns all bulls that have ever participated in this service order.
     *
     * @return int[]
     */
    public function getAllMaleCaravanIds(): array
    {
        return !empty($this->allMaleCaravanIds) ? $this->allMaleCaravanIds : $this->maleCaravanIds;
    }

    /**
     * @return int[]
     */
    public function getFemaleCaravanIds(): array
    {
        return $this->femaleCaravanIds;
    }

    /**
     * @return ServiceOrderHistoryEntity[]
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * Transition to APPROVED (Approve order)
     *
     * @throws ServiceOrderDomainException
     */
    public function approve(int $userId): void
    {
        if ($this->status !== ServiceOrderStatus::DRAFT) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::APPROVED->value);
        }

        if (empty($this->maleCaravanIds)) {
            throw ServiceOrderDomainException::domainError("Cannot approve order without bulls");
        }

        if (empty($this->femaleCaravanIds)) {
            throw ServiceOrderDomainException::domainError("Cannot approve order without females");
        }

        if ($this->isControlledService) {
            $assignedFemales = array_column($this->femaleSireAssignments, 'female_caravan_id');
            foreach ($this->femaleCaravanIds as $femaleId) {
                if (!in_array($femaleId, $assignedFemales, true)) {
                    throw ServiceOrderDomainException::domainError("All females must be assigned a sire in a controlled service");
                }
            }
        }

        $this->status = ServiceOrderStatus::APPROVED;
        $this->approvedByUserId = $userId;
        $this->approvedAt = new DateTimeImmutable();
        $this->executedAt = new DateTimeImmutable();
        $this->actualStartDate = (new DateTimeImmutable())->format('Y-m-d');
    }

    /**
     * Transition to REJECTED
     *
     * @throws ServiceOrderDomainException
     */
    public function reject(int $userId, string $reason): void
    {
        if ($this->status !== ServiceOrderStatus::DRAFT) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::REJECTED->value);
        }

        if (trim($reason) === '') {
            throw ServiceOrderDomainException::domainError("Rejection reason cannot be empty");
        }

        $this->status = ServiceOrderStatus::REJECTED;
        $this->rejectionReason = $reason;
        $this->reviewedByUserId = $userId;
        $this->reviewedAt = new DateTimeImmutable();
    }

    /**
     * The bulls left the batch before the closing: the service ended, the diagnosis goes on (APPROVED -> BULLS_WITHDRAWN).
     *
     * @throws ServiceOrderDomainException
     */
    public function withdrawBulls(string $withdrawalDate, ?string $observations = null): void
    {
        if ($this->status !== ServiceOrderStatus::APPROVED) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::BULLS_WITHDRAWN->value);
        }

        $this->status = ServiceOrderStatus::BULLS_WITHDRAWN;
        $this->actualEndDate = substr($withdrawalDate, 0, 10);
        $this->appendObservations($observations);
    }

    /**
     * Closes the order by its diagnosis (-> SUCCESS): every female has a final verdict, and the
     * pregnancy rate is fixed once, here. An order still in service ends its service the same day.
     *
     * @throws ServiceOrderDomainException
     */
    public function closeDiagnosis(string $closingDate, ?string $observations = null): void
    {
        if (!$this->status->acceptsDiagnosis()) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::SUCCESS->value);
        }

        $closingDate = substr($closingDate, 0, 10);
        $summary = $this->getDiagnosisSummary($closingDate);
        if ($summary->pendingCount() > 0) {
            throw ServiceOrderDomainException::pendingDiagnoses($summary->getPendingFemaleIds());
        }

        if ($this->status === ServiceOrderStatus::APPROVED) {
            $this->actualEndDate = $closingDate;
        }

        $this->status = ServiceOrderStatus::SUCCESS;
        $this->diagnosisCompletedAt = $closingDate;
        $this->finalPregnancyRate = $summary->pregnancyRate();
        $this->appendObservations($observations);
    }

    private function appendObservations(?string $observations): void
    {
        if ($observations === null || trim($observations) === '') {
            return;
        }

        $this->observations = $this->observations !== null && $this->observations !== ''
            ? "{$this->observations} | {$observations}"
            : $observations;
    }

    /**
     * Transition to CANCELLED
     *
     * @throws ServiceOrderDomainException
     */
    public function cancel(): void
    {
        $allowed = [
            ServiceOrderStatus::DRAFT,
            ServiceOrderStatus::APPROVED
        ];

        if (!in_array($this->status, $allowed, true)) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::CANCELLED->value);
        }

        $this->status = ServiceOrderStatus::CANCELLED;
        $this->actualEndDate = (new DateTimeImmutable())->format('Y-m-d');
    }

    /**
     * Change status dynamically based on administrative selection.
     *
     * @throws ServiceOrderDomainException
     */
    public function changeStatus(ServiceOrderStatus $newStatus, int $userId): void
    {
        if ($this->status === $newStatus) {
            return;
        }

        if ($newStatus === ServiceOrderStatus::APPROVED) {
            $this->approve($userId);
            return;
        }

        // Closing goes through the diagnosis, and the end of the service through the bull withdrawal:
        // an administrative patch cannot skip either.
        if ($newStatus === ServiceOrderStatus::SUCCESS || $newStatus === ServiceOrderStatus::BULLS_WITHDRAWN) {
            throw ServiceOrderDomainException::domainError(
                $newStatus === ServiceOrderStatus::SUCCESS
                    ? 'Una orden de servicio se cierra con su diagnóstico de gestación ("Cerrar orden").'
                    : 'La torada se retira con "Retirar torada", que mueve los toros y cierra la exposición.'
            );
        }

        if ($newStatus === ServiceOrderStatus::REJECTED) {
            $this->reject($userId, 'Status updated via administrative patch');
            return;
        }

        if ($newStatus === ServiceOrderStatus::CANCELLED) {
            $this->cancel();
            return;
        }

        if ($newStatus === ServiceOrderStatus::DRAFT) {
            // Once the bulls left, the service happened: its exposures and verdicts stay.
            if (in_array($this->status, [ServiceOrderStatus::BULLS_WITHDRAWN, ServiceOrderStatus::SUCCESS], true)) {
                throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::DRAFT->value);
            }

            $this->status = ServiceOrderStatus::DRAFT;
            $this->approvedByUserId = null;
            $this->approvedAt = null;
            $this->executedAt = null;
            $this->actualStartDate = null;
            $this->actualEndDate = null;
        }
    }
}
