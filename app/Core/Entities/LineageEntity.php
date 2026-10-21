<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\WeaningType;

use InvalidArgumentException;

final class LineageEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly int $motherId,
        private readonly ?string $motherIdentification,
        private ?int $fatherId,
        private ?string $fatherIdentification,
        private readonly ?int $gestationId,
        private readonly string $birthDate,
        private bool $isNursing = true,
        private readonly ?\DateTimeInterface $sireAssignedAt = null,
        private readonly ?string $sireIdentificationMethod = null,
        private readonly ?string $sireNotes = null,
        /** When the time at foot ended, and why: WEANED or DIED. */
        private ?string $nursingEndedAt = null,
        private ?string $nursingEndReason = null,
        /** For a weaning, the kind chosen by whoever weaned. */
        private ?WeaningType $weaningType = null
    ) {
        if ($this->caravanId === $this->motherId) {
            throw new InvalidArgumentException("An animal cannot be its own mother.");
        }
        if ($this->fatherId !== null && $this->caravanId === $this->fatherId) {
            throw new InvalidArgumentException("An animal cannot be its own father.");
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getMotherId(): int
    {
        return $this->motherId;
    }

    public function getMotherIdentification(): ?string
    {
        return $this->motherIdentification;
    }

    public function getFatherId(): ?int
    {
        return $this->fatherId;
    }

    public function getFatherIdentification(): ?string
    {
        return $this->fatherIdentification;
    }

    public function getGestationId(): ?int
    {
        return $this->gestationId;
    }

    public function getBirthDate(): string
    {
        return $this->birthDate;
    }

    public function isNursing(): bool
    {
        return $this->isNursing;
    }

    public function getSireAssignedAt(): ?\DateTimeInterface
    {
        return $this->sireAssignedAt;
    }

    public function getSireIdentificationMethod(): ?string
    {
        return $this->sireIdentificationMethod;
    }

    public function getSireNotes(): ?string
    {
        return $this->sireNotes;
    }

    /**
     * Returns true if this lineage record has no assigned sire (father_id is null).
     * Used to identify pending sire assignments after deferred birth registration.
     */
    public function hasPendingSire(): bool
    {
        return $this->fatherId === null;
    }

    public const ENDED_WEANED = 'WEANED';
    public const ENDED_DIED = 'DIED';

    /**
     * Weaned on that day, of the kind chosen: wherever the calf goes, or if it stays.
     */
    public function wean(string $date, ?WeaningType $type): void
    {
        $this->isNursing = false;
        $this->nursingEndedAt = $date;
        $this->nursingEndReason = self::ENDED_WEANED;
        $this->weaningType = $type;
    }

    /**
     * The calf died at foot: it is no longer nursing, without having been weaned.
     */
    public function endNursingByDeath(string $date): void
    {
        $this->isNursing = false;
        $this->nursingEndedAt = $date;
        $this->nursingEndReason = self::ENDED_DIED;
    }

    public function getNursingEndedAt(): ?string
    {
        return $this->nursingEndedAt;
    }

    public function getNursingEndReason(): ?string
    {
        return $this->nursingEndReason;
    }

    public function getWeaningType(): ?WeaningType
    {
        return $this->weaningType;
    }
}
