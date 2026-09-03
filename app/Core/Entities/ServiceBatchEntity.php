<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\ServiceBatchDomainException;

final class ServiceBatchEntity
{
    public function __construct(
        private BatchEntity $baseBatch,
        private ServiceBatchDetailEntity $serviceDetail
    ) {
    }

    public function getBaseBatch(): BatchEntity
    {
        return $this->baseBatch;
    }

    public function getServiceDetail(): ServiceBatchDetailEntity
    {
        return $this->serviceDetail;
    }

    /**
     * Enforces domain invariants for admitting an animal into this service batch.
     *
     * @throws ServiceBatchDomainException
     */
    public function validateAnimalAdmission(CaravanEntity $caravan): void
    {
        $caravanId = $caravan->getId() ?? 0;
        $tag = $caravan->getIdentification()->getValue();

        if ($caravan->getSex() === AnimalSex::FEMALE) {
            // Invariant 1: Female category must match
            if ($caravan->getCategoryId() !== $this->serviceDetail->getFemaleCategoryId()) {
                throw ServiceBatchDomainException::inhomogeneousFemaleCategory(
                    $caravanId,
                    $caravan->getCategoryName() ?? 'Desconocida',
                    $this->serviceDetail->getFemaleCategoryName() ?? 'Requerida'
                );
            }

            // Invariant 2: Subcategory must match if specified
            if (
                $this->serviceDetail->getFemaleSubcategoryId() !== null &&
                $caravan->getSubcategoryId() !== $this->serviceDetail->getFemaleSubcategoryId()
            ) {
                throw ServiceBatchDomainException::inhomogeneousFemaleSubcategory(
                    $caravanId,
                    $caravan->getSubcategoryName() ?? 'Ninguna',
                    $this->serviceDetail->getFemaleSubcategoryName() ?? 'Requerida'
                );
            }

            // Invariant 3: Female cannot have an active pregnancy
            if ($caravan->hasActiveGestation()) {
                throw ServiceBatchDomainException::pregnantFemaleAdmission($caravanId, $tag);
            }
        } elseif ($caravan->getSex() === AnimalSex::MALE) {
            // Invariant 4: Male category must match (Toro / Torito)
            if ($caravan->getCategoryId() !== $this->serviceDetail->getMaleCategoryId()) {
                throw ServiceBatchDomainException::invalidMaleCategory(
                    $caravanId,
                    $caravan->getCategoryName() ?? 'Desconocida',
                    $this->serviceDetail->getMaleCategoryName() ?? 'Requerida'
                );
            }
        } else {
            throw ServiceBatchDomainException::invalidAnimalSex($caravanId, 'M or H', 'OTHER');
        }
    }

    /**
     * Calculates the bull-to-female ratio (%).
     */
    public static function calculateBullRatio(int $femalesCount, int $malesCount): float
    {
        if ($femalesCount <= 0) {
            return 0.0;
        }

        return round(($malesCount / $femalesCount) * 100, 2);
    }
}
