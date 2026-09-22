<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IBullHealthEvaluationRepository;

/**
 * Andrological & clinical health guard (F5 / ADR-5).
 *
 * Shared by every path that puts a bull into a service order: the manual order, the service
 * batch wizard and the LSER-01 scanned sheet. The batch wizard used to create its order without
 * this guard, so an UNFIT bull could reach the field.
 */
final class BullServiceFitnessPolicy
{
    public function __construct(
        private readonly IBullHealthEvaluationRepository $bullHealthRepository
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function assertFit(int $maleId, int $companyId, ?string $label = null): void
    {
        $who = $label !== null ? "El reproductor {$label}" : "El reproductor ID {$maleId}";
        $bullHealth = $this->bullHealthRepository->findByCaravanId($maleId, $companyId);

        // Fail-closed: a bull that was never evaluated used to slip straight through. The
        // message is deliberately different from a rejection, so the user understands that
        // the protocol is missing rather than that the animal is sick.
        if ($bullHealth === null) {
            if ((bool) config('livestock.service_order.block_unevaluated_bulls', true)) {
                throw ServiceOrderDomainException::domainError(
                    "{$who} no tiene evaluación sanitaria registrada. " .
                    'Cargue el protocolo diagnóstico antes del entore.'
                );
            }

            return;
        }

        if (!$bullHealth->isApt()) {
            $statusLabel = $bullHealth->getStatus()->value;
            $activeDiags = array_map(fn ($d) => $d->getPathogenName() ?? $d->getPathogenCode(), $bullHealth->getActiveDiagnoses());
            $diagText = !empty($activeDiags) ? ' (' . implode(', ', $activeDiags) . ')' : '';
            throw ServiceOrderDomainException::domainError(
                "{$who} no está apto para servicio. Estado: {$statusLabel}{$diagText}."
            );
        }
    }
}
