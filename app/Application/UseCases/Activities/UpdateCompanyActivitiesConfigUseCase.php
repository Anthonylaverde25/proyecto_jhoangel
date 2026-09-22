<?php

declare(strict_types=1);

namespace App\Application\UseCases\Activities;

use App\Application\DTOs\Activities\ActivityConfigItemDTO;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IActivityRepository;

class UpdateCompanyActivitiesConfigUseCase
{
    public function __construct(
        private readonly IActivityRepository $activityRepository
    ) {
    }

    /**
     * @param int $companyId
     * @param ActivityConfigItemDTO[] $items
     * @return void
     */
    public function __invoke(int $companyId, array $items): void
    {
        if ($companyId <= 0) {
            throw new DomainException('ID de empresa inválido.');
        }

        if (empty($items)) {
            throw new DomainException('Debe proporcionar la configuración de actividades.');
        }

        // Separar habilitadas y deshabilitadas
        $enabledItems = array_values(array_filter($items, fn(ActivityConfigItemDTO $i) => $i->isEnabled));

        if (empty($enabledItems)) {
            throw new DomainException('La empresa debe tener al menos una actividad habilitada.');
        }

        // Ordenar habilitadas por sortOrder ascendente
        usort($enabledItems, fn(ActivityConfigItemDTO $a, ActivityConfigItemDTO $b) => $a->sortOrder <=> $b->sortOrder);

        $initials = array_values(array_filter($enabledItems, fn(ActivityConfigItemDTO $i) => $i->isInitial));
        $finals = array_values(array_filter($enabledItems, fn(ActivityConfigItemDTO $i) => $i->isFinal));

        // Validación o inferencia de etapa inicial
        if (count($initials) > 1) {
            throw new DomainException('Solo una actividad puede ser designada como etapa inicial.');
        }
        $targetInitialId = !empty($initials) ? $initials[0]->activityId : $enabledItems[0]->activityId;

        // Validación o inferencia de etapa final (último destino)
        if (count($finals) > 1) {
            throw new DomainException('Solo una actividad puede ser designada como último destino / etapa final.');
        }
        $targetFinalId = !empty($finals) ? $finals[0]->activityId : $enabledItems[count($enabledItems) - 1]->activityId;

        // Normalizar configuración completa
        $normalizedConfigs = [];
        $orderCounter = 1;

        // Primero las habilitadas ordenadas
        foreach ($enabledItems as $item) {
            $normalizedConfigs[] = [
                'activity_id' => $item->activityId,
                'is_enabled' => true,
                'is_initial' => ($item->activityId === $targetInitialId),
                'is_final' => ($item->activityId === $targetFinalId),
                'sort_order' => $orderCounter++,
            ];
        }

        // Luego las deshabilitadas
        $disabledItems = array_values(array_filter($items, fn(ActivityConfigItemDTO $i) => !$i->isEnabled));
        foreach ($disabledItems as $item) {
            $normalizedConfigs[] = [
                'activity_id' => $item->activityId,
                'is_enabled' => false,
                'is_initial' => false,
                'is_final' => false,
                'sort_order' => $orderCounter++,
            ];
        }

        $this->activityRepository->updateCompanyFlow($companyId, $normalizedConfigs);
    }
}
