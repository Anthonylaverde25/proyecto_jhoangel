<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\WeighingMethod;
use App\Core\Enums\WeighingTypeCode;

/**
 * A weighing purpose as the tenant sees it. The rules (ADPV, defaults) come from the code; the
 * tenant only decides whether the type is offered.
 */
final class WeighingTypeEntity
{
    public function __construct(
        private readonly int $id,
        private readonly WeighingTypeCode $code,
        private readonly string $name,
        private readonly ?string $description,
        private readonly int $displayOrder,
        private readonly bool $isSystem,
        private bool $isActive,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCode(): WeighingTypeCode
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function affectsAdpv(): bool
    {
        return $this->code->affectsAdpv();
    }

    public function getDefaultMethod(): WeighingMethod
    {
        return $this->code->defaultMethod();
    }

    public function getDefaultShrunkCondition(): ShrunkCondition
    {
        return $this->code->defaultShrunkCondition();
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }
}
