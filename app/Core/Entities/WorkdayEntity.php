<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\WorkType;
use DateTimeInterface;

final class WorkdayEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $code, // e.g. WD-20260415-01
        private readonly WorkType $type,
        private readonly DateTimeInterface $workDate,
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly ?DateTimeInterface $updatedAt = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getType(): WorkType
    {
        return $this->type;
    }

    public function getWorkDate(): DateTimeInterface
    {
        return $this->workDate;
    }
}
