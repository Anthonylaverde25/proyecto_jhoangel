<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Enums\ServiceOrderIncidentType;

interface IServiceOrderIncidentRepository
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function raise(
        int $companyId,
        int $serviceOrderId,
        ?int $femaleCaravanId,
        ServiceOrderIncidentType $type,
        string $detail,
        array $metadata,
        ?int $raisedByUserId
    ): int;

    /**
     * @return array{id: int, service_order_id: int, status: string}|null
     */
    public function find(int $incidentId, int $companyId): ?array;

    public function resolve(int $incidentId, string $resolution, ?int $userId): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listByOrder(int $serviceOrderId): array;
}
