<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ArrivalFinding;
use App\Core\Enums\ReceptionMethod;

/**
 * A caravan received on a DTE of the order. It only exists once the animal arrived: the DTE
 * declares head, not caravans, and the caravan is written down at the reception. How, when and by
 * whom it was received is a declared fact, kept as such — and so is what the chute saw on it as it
 * came off the truck (an injured eye, ear or limb), and the ING-03 sheet that recorded it.
 */
final class EntryOrderAnimalEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly string $identification,
        private readonly string $sex,
        private readonly ?int $breedPosition,
        private readonly ?int $caravanMovementId,
        private readonly ?float $entryWeight,
        private readonly string $receivedAt,
        private readonly ?ReceptionMethod $receptionMethod,
        private readonly ?int $receivedByUserId,
        private readonly ?int $categoryPosition = null,
        /** @var list<ArrivalFinding> */
        private readonly array $arrivalFindings = [],
        private readonly ?int $receiptSheetId = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getIdentification(): string
    {
        return $this->identification;
    }

    public function getSex(): string
    {
        return $this->sex;
    }

    public function getBreedPosition(): ?int
    {
        return $this->breedPosition;
    }

    /**
     * The order's category line it entered with.
     */
    public function getCategoryPosition(): ?int
    {
        return $this->categoryPosition;
    }

    public function getCaravanMovementId(): ?int
    {
        return $this->caravanMovementId;
    }

    /**
     * The weight taken on reception, if any.
     */
    public function getEntryWeight(): ?float
    {
        return $this->entryWeight;
    }

    public function getReceivedAt(): string
    {
        return $this->receivedAt;
    }

    public function getReceptionMethod(): ?ReceptionMethod
    {
        return $this->receptionMethod;
    }

    public function getReceivedByUserId(): ?int
    {
        return $this->receivedByUserId;
    }

    /**
     * @return list<ArrivalFinding>
     */
    public function getArrivalFindings(): array
    {
        return $this->arrivalFindings;
    }

    /**
     * The ING-03 sheet it was received on; null when it was received by hand.
     */
    public function getReceiptSheetId(): ?int
    {
        return $this->receiptSheetId;
    }
}
