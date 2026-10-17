<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Enums\WeighingOrderMode;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Exceptions\WeighingOrderDomainException;
use App\Core\ValueObjects\BatchWeighingMeasure;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * A weighing order: which animals go over the scale, why, and on which gut-fill basis.
 *
 * A GROUP order weighs one batch as a whole, from its loads or from an average already worked
 * out. What it measures is the batch: the measure is the document, and the weight each caravan
 * receives is the consequence.
 */
final class WeighingOrderEntity
{
    /**
     * @param WeighingOrderAnimalEntity[] $animals
     */
    public function __construct(
        private ?int $id,
        private readonly int $companyId,
        private readonly string $code,
        private TransferOrderStatus $status,
        private readonly TransferOrderKind $kind,
        private readonly WeighingOrderMode $mode,
        private readonly WeighingTypeCode $type,
        private readonly ?int $batchId,
        private readonly ShrunkCondition $shrunkCondition,
        private readonly ?float $shrinkPct,
        private readonly string $weighingDate,
        private readonly ?string $weighingTime,
        private readonly int $plannedHeadCount,
        private readonly ?int $requestedByUserId,
        private ?DateTimeInterface $emittedAt = null,
        private ?DateTimeInterface $firstExecutedAt = null,
        private ?DateTimeInterface $closedAt = null,
        private readonly ?string $responsable = null,
        private readonly ?string $observations = null,
        private readonly ?BatchWeighingMeasure $measure = null,
        private array $animals = [],
        private readonly ?string $batchName = null,
        private readonly ?DateTimeInterface $createdAt = null,
    ) {
    }

    /**
     * A cage weighing that already happened, registered in one go: the order is born executed.
     *
     * @param WeighingOrderAnimalEntity[] $animals the animals in possession in the batch
     *
     * @throws WeighingOrderDomainException
     */
    public static function registerBatchWeighing(
        int $companyId,
        string $code,
        WeighingTypeCode $type,
        int $batchId,
        ShrunkCondition $shrunkCondition,
        ?float $shrinkPct,
        string $weighingDate,
        ?string $weighingTime,
        ?int $requestedByUserId,
        ?string $responsable,
        ?string $observations,
        BatchWeighingMeasure $measure,
        array $animals,
    ): self {
        if (!$type->allowsGroupWeighing()) {
            throw WeighingOrderDomainException::domainError(
                "\"{$type->label()}\" se pesa animal por animal, no por lote.",
                'TYPE_NOT_FOR_GROUP'
            );
        }

        if ($animals === []) {
            throw WeighingOrderDomainException::domainError('El lote no tiene animales para pesar.', 'EMPTY_BATCH');
        }

        $now = new DateTimeImmutable();

        return new self(
            id: null,
            companyId: $companyId,
            code: $code,
            status: TransferOrderStatus::EXECUTED,
            kind: TransferOrderKind::REGISTERED,
            mode: WeighingOrderMode::GROUP,
            type: $type,
            batchId: $batchId,
            shrunkCondition: $shrunkCondition,
            shrinkPct: $shrinkPct,
            weighingDate: $weighingDate,
            weighingTime: $weighingTime,
            plannedHeadCount: count($animals),
            requestedByUserId: $requestedByUserId,
            emittedAt: $now,
            firstExecutedAt: $now,
            closedAt: $now,
            responsable: $responsable,
            observations: $observations,
            measure: $measure,
            animals: $animals,
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function animalByCaravanId(int $caravanId): ?WeighingOrderAnimalEntity
    {
        foreach ($this->animals as $animal) {
            if ($animal->getCaravanId() === $caravanId) {
                return $animal;
            }
        }

        return null;
    }

    public function totalHeads(): int
    {
        return $this->measure?->headCount ?? 0;
    }

    public function totalNetWeight(): float
    {
        return $this->measure?->netWeight ?? 0.0;
    }

    public function averageWeight(): ?float
    {
        return $this->measure?->averageWeight();
    }

    public function weighedCount(): int
    {
        return count(array_filter(
            $this->animals,
            static fn (WeighingOrderAnimalEntity $a): bool => $a->getStatus() === WeighingOrderAnimalEntity::STATUS_WEIGHED
        ));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getStatus(): TransferOrderStatus
    {
        return $this->status;
    }

    public function getKind(): TransferOrderKind
    {
        return $this->kind;
    }

    public function getMode(): WeighingOrderMode
    {
        return $this->mode;
    }

    public function getType(): WeighingTypeCode
    {
        return $this->type;
    }

    public function getBatchId(): ?int
    {
        return $this->batchId;
    }

    public function getBatchName(): ?string
    {
        return $this->batchName;
    }

    public function getShrunkCondition(): ShrunkCondition
    {
        return $this->shrunkCondition;
    }

    public function getShrinkPct(): ?float
    {
        return $this->shrinkPct;
    }

    public function getWeighingDate(): string
    {
        return $this->weighingDate;
    }

    public function getWeighingTime(): ?string
    {
        return $this->weighingTime;
    }

    public function getPlannedHeadCount(): int
    {
        return $this->plannedHeadCount;
    }

    public function getRequestedByUserId(): ?int
    {
        return $this->requestedByUserId;
    }

    public function getEmittedAt(): ?DateTimeInterface
    {
        return $this->emittedAt;
    }

    public function getFirstExecutedAt(): ?DateTimeInterface
    {
        return $this->firstExecutedAt;
    }

    public function getClosedAt(): ?DateTimeInterface
    {
        return $this->closedAt;
    }

    public function getResponsable(): ?string
    {
        return $this->responsable;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getMeasure(): ?BatchWeighingMeasure
    {
        return $this->measure;
    }

    /** @return WeighingOrderAnimalEntity[] */
    public function getAnimals(): array
    {
        return $this->animals;
    }
}
