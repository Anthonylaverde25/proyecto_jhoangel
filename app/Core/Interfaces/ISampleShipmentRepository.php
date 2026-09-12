<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\SampleShipmentEntity;

interface ISampleShipmentRepository
{
    /**
     * ADR-36: shipments of the establishment, newest first. Not scoped to an act — a cooler is
     * not part of one chute session.
     *
     * @return array<SampleShipmentEntity>
     */
    public function findAll(int $companyId, ?int $veterinarianId = null): array;

    public function findById(int $id, int $companyId): ?SampleShipmentEntity;

    /**
     * ADR-30: tubes still in the professional's hands — drawn under a signed act of theirs and
     * never dispatched. This is what the shipment screen offers, across every act at once.
     *
     * @return array<array<string, mixed>>
     */
    public function findPendingTubes(int $veterinarianId, int $companyId): array;

    /**
     * §3.9: the suggestion list is computed from what has already been recorded. Nobody ever
     * registers a laboratory; the tenth time somebody types "Rosario" it is offered back.
     *
     * @return array<array<string, mixed>>
     */
    public function findInstitutionSuggestions(int $companyId, string $search = ''): array;
}
