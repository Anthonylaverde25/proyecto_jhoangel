<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\CreateBatchDTO;
use App\Application\UseCases\Batches\CreateBatchUseCase;
use App\Core\Entities\EntryOrderEntity;

/**
 * Creates the external batch of a confirmed purchase: on the seller's establishment, empty, with
 * the references the order declared. Without activity nor batch type: its animals are not in the
 * productive flow yet, and the own batch they are assigned to later is the one classified. The batch only receives what it already models with the same
 * meaning (weights as an initial reference, "sabe comer"); the facts of the purchase
 * stay on the order. The declared age is a range, so the batch's single age is left unset.
 */
final class EntryOrderBatchFactory
{
    public function __construct(private readonly CreateBatchUseCase $createBatch)
    {
    }

    public function create(EntryOrderEntity $order): int
    {
        $troop = $order->getTroop();

        $batch = ($this->createBatch)(new CreateBatchDTO(
            name: $order->getBatchName(),
            farmId: $troop->farmId,
            observaciones: $troop->observations,
            weight: $troop->estimatedWeight,
            knowsToEat: $troop->knowsToEat,
            // Undeclared on purpose: the external batch only holds the purchase. The own batch the
            // animals are assigned to later is the one that declares its management system.
            isConfined: null,
            ageInMonths: null,
            minWeight: $troop->minWeight,
            maxWeight: $troop->maxWeight
        ));

        return (int) $batch->getId();
    }
}
