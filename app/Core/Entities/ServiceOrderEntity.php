<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ServiceMethod;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\ValueObjects\ServiceOrderDiagnosisSummary;
use DateTimeImmutable;
use DateTimeInterface;

final class ServiceOrderEntity
{
    /** Days after the bull withdrawal from which a pregnancy is reliably detected at the chute. */
    public const SUGGESTED_DIAGNOSIS_DELAY_DAYS = 45;

    /** Days after the last insemination for the early scan of an AI service (DB9). */
    public const SUGGESTED_AI_DIAGNOSIS_DELAY_DAYS = 30;

    /**
     * @param int[] $maleCaravanIds
     * @param int[] $femaleCaravanIds
     * @param ServiceOrderHistoryEntity[] $history
     * @param array<int, array{female_caravan_id: int, exposed_from: ?string, exposed_until: ?string, exit_reason: ?string, joined_after_start: bool, reproductive_status?: ?string, diagnosed_at?: ?string, last_insemination_on?: ?string, planned_semen_item_id?: ?int, not_inseminated_reason?: ?string}> $femaleExposures
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
        private ?int $parentServiceOrderId = null,
        private ServiceMethod $serviceMethod = ServiceMethod::NATURAL
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
        return $this->isInsemination()
            ? ServiceOrderDiagnosisSummary::fromInseminatedFemales($this->femaleExposures, $this->hadRepasoBulls(), $exposureEndsOn)
            : ServiceOrderDiagnosisSummary::fromFemales($this->femaleExposures, $exposureEndsOn);
    }

    public function getServiceMethod(): ServiceMethod
    {
        return $this->serviceMethod;
    }

    public function isInsemination(): bool
    {
        return $this->serviceMethod === ServiceMethod::AI;
    }

    /** Bulls in an AI order only come as repaso (IA10): any of them ever means the females were exposed. */
    public function hadRepasoBulls(): bool
    {
        return $this->isInsemination() && $this->getAllMaleCaravanIds() !== [];
    }

    /**
     * Where the inseminations of an AI order stand (semen S-B): derived, never stored. Null for a
     * natural order.
     *
     * @return array{females: int, inseminated: int, not_inseminated: int, last_insemination_on: ?string, repaso_bulls_active: int, had_repaso_bulls: bool}|null
     */
    public function getInseminationSummary(): ?array
    {
        if (!$this->isInsemination()) {
            return null;
        }

        $inseminated = 0;
        foreach ($this->femaleExposures as $female) {
            if (($female['last_insemination_on'] ?? null) !== null) {
                $inseminated++;
            }
        }

        return [
            'females' => count($this->femaleExposures),
            'inseminated' => $inseminated,
            'not_inseminated' => count($this->femaleExposures) - $inseminated,
            'last_insemination_on' => $this->getLastInseminationDate(),
            'repaso_bulls_active' => count($this->getActiveMaleCaravanIds()),
            'had_repaso_bulls' => $this->hadRepasoBulls(),
        ];
    }

    /** Latest live insemination of the whole order, or null before the first one. */
    public function getLastInseminationDate(): ?string
    {
        $dates = array_filter(array_map(
            static fn (array $female): ?string => $female['last_insemination_on'] ?? null,
            $this->femaleExposures
        ));

        return $dates === [] ? null : max($dates);
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
        if ($this->isInsemination()) {
            return $this->getSuggestedInseminationDiagnosisDate();
        }

        if ($this->actualEndDate === null || $this->status === ServiceOrderStatus::APPROVED) {
            return null;
        }

        return self::addDays($this->actualEndDate, self::SUGGESTED_DIAGNOSIS_DELAY_DAYS);
    }

    /**
     * DB9: an AI service is scanned 30 days after its last insemination; with repaso bulls, not
     * before 45 days after they left either.
     */
    private function getSuggestedInseminationDiagnosisDate(): ?string
    {
        $lastInsemination = $this->getLastInseminationDate();
        if ($lastInsemination === null) {
            return null;
        }

        $suggested = self::addDays($lastInsemination, self::SUGGESTED_AI_DIAGNOSIS_DELAY_DAYS);
        if ($this->hadRepasoBulls()) {
            if ($this->status === ServiceOrderStatus::APPROVED || $this->actualEndDate === null) {
                return null;
            }
            $suggested = max($suggested, self::addDays($this->actualEndDate, self::SUGGESTED_DIAGNOSIS_DELAY_DAYS));
        }

        return $suggested;
    }

    private static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable(substr($date, 0, 10)))->modify("+{$days} days")->format('Y-m-d');
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

        if ($this->serviceMethod->needsBulls() && empty($this->maleCaravanIds)) {
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
        // The repaso bulls of an AI order leave the same way, and with them its service ends (IA10).
        $ended = $this->isInsemination() ? ServiceOrderStatus::SERVICE_ENDED : ServiceOrderStatus::BULLS_WITHDRAWN;
        if ($this->status !== ServiceOrderStatus::APPROVED) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, $ended->value);
        }

        $this->status = $ended;
        $this->actualEndDate = substr($withdrawalDate, 0, 10);
        $this->appendObservations($observations);
    }

    /**
     * Ends an AI service without closing the order, so the batch is free before the diagnosis
     * (APPROVED -> SERVICE_ENDED). With repaso bulls in the batch the end is their withdrawal.
     *
     * @throws ServiceOrderDomainException
     */
    public function endService(string $endDate, ?string $observations = null): void
    {
        if (!$this->isInsemination()) {
            throw ServiceOrderDomainException::domainError('Una monta natural termina al retirar la torada.');
        }
        if ($this->status !== ServiceOrderStatus::APPROVED) {
            throw ServiceOrderDomainException::invalidStateTransition($this->status->value, ServiceOrderStatus::SERVICE_ENDED->value);
        }
        if ($this->getActiveMaleCaravanIds() !== []) {
            throw ServiceOrderDomainException::domainError('Hay toros de repaso en el lote: el servicio termina al retirar la torada.');
        }

        $endDate = substr($endDate, 0, 10);
        $lastInsemination = $this->getLastInseminationDate();
        if ($lastInsemination !== null && $endDate < $lastInsemination) {
            throw ServiceOrderDomainException::domainError("El servicio no puede terminar antes de la última inseminación ({$lastInsemination}).");
        }

        $this->status = ServiceOrderStatus::SERVICE_ENDED;
        $this->actualEndDate = $endDate;
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
    public function cancel(int $liveInseminations = 0): void
    {
        if ($liveInseminations > 0) {
            throw ServiceOrderDomainException::domainError(
                "La orden tiene {$liveInseminations} inseminación(es) vigente(s): anulalas antes de cancelarla."
            );
        }

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
    public function changeStatus(ServiceOrderStatus $newStatus, int $userId, int $liveInseminations = 0): void
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
        if ($newStatus === ServiceOrderStatus::SUCCESS || $newStatus->hasServiceEnded()) {
            throw ServiceOrderDomainException::domainError(
                $newStatus === ServiceOrderStatus::SUCCESS
                    ? 'Una orden de servicio se cierra con su diagnóstico de gestación ("Cerrar orden").'
                    : 'El servicio termina con "Retirar torada" (o "Terminar servicio" en una IA sin toros), que cierra la exposición.'
            );
        }

        if ($newStatus === ServiceOrderStatus::REJECTED) {
            $this->reject($userId, 'Status updated via administrative patch');
            return;
        }

        if ($newStatus === ServiceOrderStatus::CANCELLED) {
            $this->cancel($liveInseminations);
            return;
        }

        if ($newStatus === ServiceOrderStatus::DRAFT) {
            // Once the bulls left, the service happened: its exposures and verdicts stay.
            if ($this->status->hasServiceEnded() || $this->status === ServiceOrderStatus::SUCCESS) {
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
