<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Enums\ReproductiveAptitudeStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IBullHealthEvaluationRepository;
use App\Core\Interfaces\ICaravanRepository;

/**
 * Admission guard for a bull entering a service order (F5 / ADR-5).
 *
 * Shared by every path that puts a bull into a service order: the manual order, the service
 * batch wizard, the bull replacement and the LSER-01 scanned sheet.
 *
 * The category comes first: a calf, a steer or a castrated yearling is never a bull, whatever
 * else is said about it (animal_categories.is_reproductive). Then two independent dimensions
 * are combined, first match wins:
 *   1. The owner's decision (caravans.is_breeding_eligible): an excluded bull never enters.
 *   2. The veterinary verdict, only where one exists: UNFIT and IN_TREATMENT block.
 *   3. No evaluation or PENDING_EVALUATION: admitted, unless the deployment requires a
 *      sanitary certificate (livestock.service_order.require_sanitary_certification).
 * The veterinary module is optional: a bull nobody evaluated is the owner's call.
 */
final class BullServiceFitnessPolicy
{
    public function __construct(
        private readonly IBullHealthEvaluationRepository $bullHealthRepository,
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * @return ReproductiveAptitudeStatus|null The sanitary status the bull is admitted with,
     *                                         to be kept on the order; null = never evaluated.
     *
     * @throws ServiceOrderDomainException
     */
    public function assertFit(int $maleId, int $companyId, ?string $label = null): ?ReproductiveAptitudeStatus
    {
        $who = $label !== null ? "El reproductor {$label}" : "El reproductor ID {$maleId}";

        $category = $this->caravanRepository->findCategoryBreedingProfile($maleId);
        if ($category !== null && !$category['is_reproductive']) {
            throw ServiceOrderDomainException::domainError(
                "{$who} es {$category['name']}: sólo una categoría reproductiva (torito o toro) entra como reproductor."
            );
        }

        $eligibility = $this->caravanRepository->findBreedingEligibility($maleId);
        if ($eligibility !== null && !$eligibility->isEligible) {
            throw ServiceOrderDomainException::domainError(
                "{$who} está excluido de reproducción ({$eligibility->reasonLabel()}). Habilítelo para incluirlo."
            );
        }

        $bullHealth = $this->bullHealthRepository->findByCaravanId($maleId, $companyId);
        $status = $bullHealth?->getStatus();

        if ($bullHealth !== null && ($bullHealth->isUnfit() || $bullHealth->isUnderTreatment())) {
            $activeDiags = array_map(fn ($d) => $d->getPathogenName() ?? $d->getPathogenCode(), $bullHealth->getActiveDiagnoses());
            $diagText = !empty($activeDiags) ? ' (' . implode(', ', $activeDiags) . ')' : '';
            throw ServiceOrderDomainException::domainError(
                "{$who} no está apto para servicio. Estado: {$status->value}{$diagText}."
            );
        }

        if ($status === ReproductiveAptitudeStatus::APT) {
            return $status;
        }

        if ((bool) config('livestock.service_order.require_sanitary_certification', false)) {
            // Distinct messages, so the user knows whether the protocol is missing or unfinished.
            throw ServiceOrderDomainException::domainError(
                $bullHealth === null
                    ? "{$who} no tiene evaluación sanitaria registrada. Cargue el protocolo diagnóstico antes del entore."
                    : "{$who} tiene la evaluación sanitaria pendiente. Complete el protocolo diagnóstico antes del entore."
            );
        }

        return $status;
    }
}
