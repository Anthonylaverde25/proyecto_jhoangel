<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\WeighingMethod;
use App\Core\Enums\WeighingSource;
use App\Core\Enums\WeighingTypeCode;
use App\Core\ValueObjects\WeighingReading;

final readonly class RecordCaravanWeightDTO
{
    public function __construct(
        public int $caravanId,
        public float $weight,
        public string $weighingDate,
        public ?string $notes = null,
        public WeighingTypeCode $type = WeighingTypeCode::CONTROL,
        public ?WeighingMethod $method = null,
        public ?ShrunkCondition $shrunkCondition = null,
        public ?float $shrinkPct = null,
        public ?string $weighingTime = null,
        public WeighingSource $source = WeighingSource::MANUAL,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            caravanId: (int) $data['caravan_id'],
            weight: (float) $data['weight'],
            weighingDate: (string) $data['weighing_date'],
            notes: $data['notes'] ?? null,
            type: isset($data['weighing_type']) ? WeighingTypeCode::from($data['weighing_type']) : WeighingTypeCode::CONTROL,
            method: isset($data['method']) ? WeighingMethod::from($data['method']) : null,
            shrunkCondition: isset($data['shrunk_condition']) ? ShrunkCondition::from($data['shrunk_condition']) : null,
            shrinkPct: isset($data['shrink_pct']) ? (float) $data['shrink_pct'] : null,
            weighingTime: $data['weighing_time'] ?? null,
        );
    }

    /**
     * Unstated method and shrink basis fall back to what the type usually has. A single animal
     * is never an average: a type usually weighed in groups (an arrival) is individual here.
     */
    public function toReading(): WeighingReading
    {
        $method = $this->method ?? $this->type->defaultMethod();

        return new WeighingReading(
            weight: $this->weight,
            date: new \DateTimeImmutable($this->weighingDate),
            type: $this->type,
            method: $method === WeighingMethod::AVERAGE ? WeighingMethod::INDIVIDUAL : $method,
            source: $this->source,
            shrunkCondition: $this->shrunkCondition ?? $this->type->defaultShrunkCondition(),
            shrinkPct: $this->shrinkPct,
            time: $this->weighingTime,
            notes: $this->notes,
        );
    }
}
