<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BullServiceFitnessPolicy;

/**
 * The bulls of a planned order: they must be males of the company. Whether they are fit or free
 * today is only a warning, since it is checked again on the day the order starts.
 */
final class PlannedBullsReview
{
    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly ICaravanRepository $caravans,
        private readonly BullServiceFitnessPolicy $fitnessPolicy
    ) {
    }

    /**
     * @param int[] $maleIds
     * @return list<string>
     *
     * @throws ServiceOrderDomainException
     */
    public function warnings(array $maleIds, int $companyId): array
    {
        $sexes = $this->orders->verifyAnimalsSexAndOwnership($maleIds, $companyId);
        $busy = $this->orders->findActiveOrdersByCaravans($maleIds, $companyId);
        $warnings = [];

        foreach ($maleIds as $maleId) {
            if (!isset($sexes[$maleId])) {
                throw ServiceOrderDomainException::domainError("El reproductor con ID {$maleId} no fue encontrado.");
            }
            if ($sexes[$maleId] !== AnimalSex::MALE->value) {
                throw ServiceOrderDomainException::invalidAnimalSex($maleId, 'male', $sexes[$maleId]);
            }

            $tag = $this->caravans->findById($maleId)?->getIdentification()->getValue() ?? "#{$maleId}";
            if (in_array($maleId, $busy, false)) {
                $warnings[] = "El toro {$tag} está hoy en otra orden activa: debe quedar libre antes de iniciar.";
            }
            try {
                $this->fitnessPolicy->assertFit($maleId, $companyId, $tag);
            } catch (ServiceOrderDomainException $e) {
                $warnings[] = $e->getMessage();
            }
        }

        return $warnings;
    }
}
