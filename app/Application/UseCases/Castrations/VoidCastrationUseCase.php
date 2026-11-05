<?php

declare(strict_types=1);

namespace App\Application\UseCases\Castrations;

use App\Application\Services\CaravanRecategorizer;
use App\Core\Enums\CategoryChangeCause;
use App\Core\Enums\CategoryChangeSource;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\ICaravanCastrationRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICategoryChangeRepository;
use Illuminate\Support\Facades\DB;

final class VoidCastrationUseCase
{
    public function __construct(
        private readonly ICaravanCastrationRepository $castrationRepository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly IAnimalCategoryRepository $categoryRepository,
        private readonly ICategoryChangeRepository $categoryChangeRepository,
        private readonly CaravanRecategorizer $recategorizer
    ) {
    }

    /**
     * @return array{
     *     id: int,
     *     caravan_id: int,
     *     castrated_on: string,
     *     method: ?string,
     *     notes: ?string,
     *     voided_at: string,
     *     void_reason: ?string
     * }
     */
    public function __invoke(int $companyId, int $castrationId, ?string $reason, ?int $userId): array
    {
        return DB::transaction(function () use ($companyId, $castrationId, $reason, $userId): array {
            $castration = $this->castrationRepository->findActive($castrationId);
            if ($castration === null || $castration['company_id'] !== $companyId) {
                throw new DomainException('La castración no existe o ya fue anulada.');
            }

            $caravanId = $castration['caravan_id'];
            $caravan = $this->caravanRepository->findById($caravanId);
            if ($caravan === null || $caravan->getCompanyId() !== $companyId) {
                throw new DomainException('La caravana vinculada no existe o no pertenece a la empresa.');
            }

            $fromCategoryCode = strtoupper(trim((string) ($castration['from_category_code'] ?? '')));

            // CS11: Una castración se anula sólo si la categoría del animal no cambió después de ella
            $latestChange = $this->categoryChangeRepository->latestFor($caravanId);

            if ($fromCategoryCode === 'TORITO') {
                // If the animal changed category after the castration event, reject
                if ($latestChange !== null && ($latestChange->getSourceType() !== CategoryChangeSource::CASTRATION || $latestChange->getSourceId() !== $castrationId)) {
                    throw new DomainException('La categoría cambió después de la castración: no se puede anular.');
                }

                // Revert category back to TORITO with cause CORRECTION
                $toritoCategory = $this->categoryRepository->findByCode('TORITO');
                if ($toritoCategory === null || $toritoCategory->getId() === null) {
                    throw new DomainException('La categoría TORITO no existe en el catálogo.');
                }

                $this->recategorizer->change(
                    caravan: $caravan,
                    toCategoryId: (int) $toritoCategory->getId(),
                    toSubcategoryId: null,
                    cause: CategoryChangeCause::CORRECTION,
                    occurredOn: date('Y-m-d'),
                    source: CategoryChangeSource::CASTRATION,
                    sourceId: $castrationId,
                    userId: $userId
                );
            } else {
                // TERNERO: if category changed away from TERNERO after castration, reject
                $currentCatCode = strtoupper(trim((string) $caravan->getCategoryCode()));
                if ($currentCatCode !== 'TERNERO' && $latestChange !== null) {
                    throw new DomainException('La categoría cambió después de la castración: no se puede anular.');
                }
            }

            $this->castrationRepository->void($castrationId, $reason, $userId);

            return [
                'id' => $castrationId,
                'caravan_id' => $caravanId,
                'castrated_on' => $castration['castrated_on'],
                'method' => $castration['method']?->value,
                'notes' => $castration['notes'],
                'voided_at' => date('Y-m-d H:i:s'),
                'void_reason' => $reason,
            ];
        });
    }
}

