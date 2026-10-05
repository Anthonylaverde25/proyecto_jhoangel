<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;

/**
 * Writes down what was agreed with the provider about an incident. The order's status is untouched.
 */
final class ResolveEntryOrderIncidentUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $incidentId, int $companyId, ?int $userId, string $resolution): EntryOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();

        $incident = $order->resolveIncident($incidentId, $resolution, $userId);

        return $this->repository->save($order, $userId, $incident->getResolution(), [
            'incident_resolved' => $incident->getType()->value,
            'incident_id' => $incident->getId(),
        ]);
    }
}
