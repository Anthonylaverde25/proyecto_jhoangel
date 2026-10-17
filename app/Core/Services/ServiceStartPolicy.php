<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\BatchEntity;
use App\Core\Exceptions\ServiceOrderDomainException;

/**
 * Invariants for putting an operational batch into service.
 *
 * Shared by every path that opens a service: "Iniciar servicio" on the batch, the planned
 * DRAFT order and the LSER-01 scanned sheet. A service is an order over a perennial own
 * Cría batch; the batch never moves and the females stay where they are.
 */
final class ServiceStartPolicy
{
    /**
     * @throws ServiceOrderDomainException
     */
    public function assertBatchCanStartService(BatchEntity $batch, bool $hasActiveService): void
    {
        if (!$batch->isOwn()) {
            throw ServiceOrderDomainException::domainError(
                "El lote '{$batch->getName()}' es externo: un servicio sólo se inicia sobre un lote propio."
            );
        }

        if (!$batch->isCria()) {
            throw ServiceOrderDomainException::domainError(
                "El lote '{$batch->getName()}' no pertenece a la actividad Cría."
            );
        }

        if ($hasActiveService) {
            throw ServiceOrderDomainException::domainError(
                "El lote '{$batch->getName()}' ya tiene un servicio activo en curso."
            );
        }
    }

    /**
     * Resolves which females of the batch are exposed to the service.
     *
     * Natural service exposes every female of the batch: the bulls share the paddock with all of
     * them, so a partial selection would be a fiction. Controlled service and AI can pick a subset.
     *
     * The owner's breeding exclusion only filters a controlled service / AI: the caller passes
     * the eligible females as $batchFemaleIds, and the excluded ones so that picking one of
     * them gets its own message.
     *
     * @param int[] $batchFemaleIds    Serviceable females physically in the batch right now (not pregnant).
     * @param int[]|null $requestedIds Females picked by the operator (controlled service / AI only).
     * @param int[] $excludedFemaleIds Females of the batch the owner excluded from breeding.
     * @return int[]
     *
     * @throws ServiceOrderDomainException
     */
    public function resolveExposedFemales(
        bool $isControlledService,
        array $batchFemaleIds,
        ?array $requestedIds,
        array $excludedFemaleIds = []
    ): array {
        $batchFemaleIds = array_values(array_unique(array_map('intval', $batchFemaleIds)));

        if (!$isControlledService || $requestedIds === null || $requestedIds === []) {
            $exposed = $batchFemaleIds;
        } else {
            $requestedIds = array_values(array_unique(array_map('intval', $requestedIds)));
            $excluded = array_values(array_intersect($requestedIds, array_map('intval', $excludedFemaleIds)));
            if ($excluded !== []) {
                throw ServiceOrderDomainException::domainError(
                    'Hay vientres seleccionados que están excluidos de reproducción (IDs: ' . implode(', ', $excluded) . '). ' .
                    'Habilítelos antes de incluirlos en el servicio.'
                );
            }
            $outside = array_diff($requestedIds, $batchFemaleIds);
            if ($outside !== []) {
                throw ServiceOrderDomainException::domainError(
                    'Hay vientres seleccionados que no están en el lote o ya están preñados (IDs: ' . implode(', ', $outside) . '). ' .
                    'Sólo se sirven vientres vacíos que estén en el lote.'
                );
            }
            $exposed = $requestedIds;
        }

        if ($exposed === []) {
            throw ServiceOrderDomainException::domainError('El lote no cuenta con vientres vacíos disponibles para el servicio.');
        }

        return $exposed;
    }

    /**
     * @param int[] $maleIds
     *
     * @throws ServiceOrderDomainException
     */
    public function assertHasBulls(array $maleIds): void
    {
        if ($maleIds === []) {
            throw ServiceOrderDomainException::domainError('Debe seleccionar al menos un reproductor macho para el servicio.');
        }
    }
}
