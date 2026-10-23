<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\SemenPreservation;
use App\Core\Enums\SemenType;
use App\Core\Enums\StrawColor;
use App\Core\Enums\StrawSize;
use App\Core\Exceptions\SemenException;

/**
 * The straw profile of a semen item (plan 4.2): one item per sire and type (DA2). The straw data is
 * completed later from the item (P12); sire and type are fixed once the item moved (SI3).
 */
final readonly class SemenProfileEntity
{
    public function __construct(
        public int $itemId,
        public int $companyId,
        public int $sireId,
        public SemenType $type,
        public SemenPreservation $preservation = SemenPreservation::FROZEN,
        public ?StrawSize $strawSize = null,
        public ?StrawColor $strawColor = null,
        public ?StrawColor $capColor = null,
        public ?SemenSireEntity $sire = null,
    ) {
    }

    public function withStraw(SemenPreservation $preservation, ?StrawSize $size, ?StrawColor $color, ?StrawColor $cap): self
    {
        return new self($this->itemId, $this->companyId, $this->sireId, $this->type, $preservation, $size, $color, $cap, $this->sire);
    }

    public function retyped(int $sireId, SemenType $type, bool $hasMovements): self
    {
        if (($sireId !== $this->sireId || $type !== $this->type) && $hasMovements) {
            throw SemenException::on('semen_type', 'El padre y el tipo no cambian: el semen ya tiene movimientos.');
        }

        return new self($this->itemId, $this->companyId, $sireId, $type, $this->preservation, $this->strawSize, $this->strawColor, $this->capColor);
    }
}
