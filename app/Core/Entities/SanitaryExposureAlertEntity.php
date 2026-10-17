<?php

declare(strict_types=1);

namespace App\Core\Entities;

use DateTimeImmutable;

/**
 * Read model of a sanitary exposure alert: a bull found positive to a contagious pathogen and
 * the females that shared a service with him inside the risk window.
 */
final readonly class SanitaryExposureAlertEntity
{
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_RESOLVED = 'RESOLVED';

    /**
     * @param array{service_order_id: int, code: string, batch_id: int}|null $bullActiveOrder The order the bull
     *        is still working in, if any: he must be taken out.
     * @param list<ExposedFemaleEntry> $females Empty when listed without detail.
     */
    public function __construct(
        public int $id,
        public int $bullId,
        public string $bullTag,
        public ?array $bullActiveOrder,
        public string $pathogenCode,
        public ?string $pathogenName,
        public DateTimeImmutable $detectedOn,
        public DateTimeImmutable $riskWindowFrom,
        public string $detail,
        public string $status,
        public ?string $resolution,
        public ?DateTimeImmutable $resolvedAt,
        public ?string $resolvedByName,
        public int $femalesCount,
        public int $reviewedCount,
        public array $females = []
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function pathogenLabel(): string
    {
        return $this->pathogenName ?? $this->pathogenCode;
    }
}
