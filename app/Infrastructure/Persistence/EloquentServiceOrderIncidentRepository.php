<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Enums\ServiceOrderIncidentStatus;
use App\Core\Enums\ServiceOrderIncidentType;
use App\Core\Interfaces\IServiceOrderIncidentRepository;
use App\Models\ServiceOrderIncident;

class EloquentServiceOrderIncidentRepository implements IServiceOrderIncidentRepository
{
    public function raise(
        int $companyId,
        int $serviceOrderId,
        ?int $femaleCaravanId,
        ServiceOrderIncidentType $type,
        string $detail,
        array $metadata,
        ?int $raisedByUserId
    ): int {
        $incident = ServiceOrderIncident::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'service_order_id' => $serviceOrderId,
            'female_caravan_id' => $femaleCaravanId,
            'type' => $type->value,
            'detail' => $detail,
            'metadata' => $metadata,
            'status' => ServiceOrderIncidentStatus::OPEN->value,
            'raised_by_user_id' => $raisedByUserId,
        ]);

        return (int) $incident->id;
    }

    public function find(int $incidentId, int $companyId): ?array
    {
        $incident = ServiceOrderIncident::withoutGlobalScopes()
            ->where('id', $incidentId)
            ->where('company_id', $companyId)
            ->first(['id', 'service_order_id', 'status']);

        if ($incident === null) {
            return null;
        }

        return [
            'id' => (int) $incident->id,
            'service_order_id' => (int) $incident->service_order_id,
            'status' => (string) $incident->status,
        ];
    }

    public function resolve(int $incidentId, string $resolution, ?int $userId): void
    {
        ServiceOrderIncident::withoutGlobalScopes()
            ->where('id', $incidentId)
            ->update([
                'status' => ServiceOrderIncidentStatus::RESOLVED->value,
                'resolution' => $resolution,
                'resolved_by_user_id' => $userId,
                'resolved_at' => now(),
            ]);
    }

    public function listByOrder(int $serviceOrderId): array
    {
        return ServiceOrderIncident::withoutGlobalScopes()
            ->with('femaleCaravan:id,identification')
            ->where('service_order_id', $serviceOrderId)
            ->orderByDesc('id')
            ->get()
            ->map(function (ServiceOrderIncident $incident): array {
                $type = ServiceOrderIncidentType::tryFrom((string) $incident->type);
                $status = ServiceOrderIncidentStatus::tryFrom((string) $incident->status);

                return [
                    'id' => (int) $incident->id,
                    'type' => (string) $incident->type,
                    'type_label' => $type?->label() ?? (string) $incident->type,
                    'female_caravan_id' => $incident->female_caravan_id,
                    'female_identification' => $incident->femaleCaravan?->identification,
                    'detail' => (string) $incident->detail,
                    'metadata' => $incident->metadata ?? [],
                    'status' => (string) $incident->status,
                    'status_label' => $status?->label() ?? (string) $incident->status,
                    'resolution' => $incident->resolution,
                    'resolved_at' => $incident->resolved_at?->format('Y-m-d H:i:s'),
                    'created_at' => $incident->created_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->all();
    }
}
