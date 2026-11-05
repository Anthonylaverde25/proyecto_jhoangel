<?php

declare(strict_types=1);

namespace App\Application\UseCases\Castrations;

use App\Application\DTOs\RegisterCastrationDTO;
use App\Application\Services\CaravanRecategorizer;
use App\Core\Enums\CategoryChangeCause;
use App\Core\Enums\CategoryChangeSource;
use App\Core\Exceptions\RegisterCastrationException;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\ICaravanCastrationRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\CastrationPolicy;
use Illuminate\Support\Facades\DB;

final class RegisterCastrationUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICaravanCastrationRepository $castrationRepository,
        private readonly IAnimalCategoryRepository $categoryRepository,
        private readonly CaravanRecategorizer $recategorizer,
        private readonly CastrationPolicy $policy = new CastrationPolicy()
    ) {
    }

    /**
     * @return list<array{
     *     id: int,
     *     caravan_id: int,
     *     castrated_on: string,
     *     method: ?string,
     *     notes: ?string,
     *     from_category_id: ?int
     * }>
     *
     * @throws RegisterCastrationException
     */
    public function __invoke(RegisterCastrationDTO $dto): array
    {
        return DB::transaction(function () use ($dto): array {
            $caravanIds = array_map(fn (array $item) => (int) $item['caravan_id'], $dto->items);

            $activeCastrations = $this->castrationRepository->activeByCaravans($caravanIds);
            $activeServiceBulls = $this->castrationRepository->activeServiceBulls($caravanIds);

            $errors = [];
            $caravansById = [];

            // 1. Validate date (CS5)
            $today = date('Y-m-d');
            if ($dto->castratedOn > $today) {
                $errors['castrated_on'] = ['La fecha de castración no puede ser futura.'];
            }

            // 2. Validate row by row (CS1 - CS7)
            foreach ($dto->items as $index => $item) {
                $caravanId = (int) $item['caravan_id'];
                $fieldKey = "items.{$index}.caravan_id";
                $targetFieldKey = "items.{$index}.target_category_code";

                $caravan = $this->caravanRepository->findById($caravanId);
                if ($caravan === null || $caravan->getCompanyId() !== $dto->companyId) {
                    $errors[$fieldKey][] = 'La caravana no existe o no pertenece a la empresa.';
                    continue;
                }

                $caravansById[$caravanId] = $caravan;

                // CS3: dead or exited animals
                if ($caravan->isDead()) {
                    $errors[$fieldKey][] = 'El animal está muerto.';
                    continue;
                }
                if ($caravan->getExit() !== null) {
                    $errors[$fieldKey][] = 'El animal ya egresó del establecimiento.';
                    continue;
                }

                // CS1 & CS2: sex, category and existing active castration
                $activeCastration = $activeCastrations[$caravanId] ?? null;
                $refusal = $this->policy->refusal($caravan->getSex(), $caravan->getCategoryCode(), $activeCastration);
                if ($refusal !== null) {
                    $errors[$fieldKey][] = $refusal;
                    continue;
                }

                $catCode = strtoupper(trim((string) $caravan->getCategoryCode()));

                // CS4: torito in active service order
                if ($catCode === 'TORITO' && isset($activeServiceBulls[$caravanId])) {
                    $soCode = $activeServiceBulls[$caravanId];
                    $errors[$fieldKey][] = "trabaja en el servicio {$soCode}: retiralo con «Reemplazar/retirar toro»";
                }

                // CS5: birth date check
                $birthDate = $caravan->getLineage()?->getBirthDate();
                if ($birthDate !== null && $dto->castratedOn < $birthDate) {
                    $errors[$fieldKey][] = "La fecha de castración no puede ser anterior al nacimiento ({$birthDate}).";
                }

                // CS6: target category rules
                $targetCode = $this->resolveTargetCategoryCode($item);

                if ($catCode === 'TORITO') {
                    if ($targetCode === null || !in_array($targetCode, ['NOVILLITO', 'NOVILLO'], true)) {
                        $errors[$targetFieldKey][] = 'Un torito castrado requiere categoría destino: NOVILLITO o NOVILLO.';
                    }
                } elseif ($catCode === 'TERNERO') {
                    if ($targetCode !== null && $targetCode !== 'TERNERO') {
                        $errors[$targetFieldKey][] = 'Un ternero castrado sigue TERNERO; no lleva categoría destino.';
                    }
                }
            }

            if ($errors !== []) {
                throw RegisterCastrationException::many($errors);
            }

            // 3. Persist all castrations and apply category transitions
            $results = [];

            foreach ($dto->items as $item) {
                $caravanId = (int) $item['caravan_id'];
                $caravan = $caravansById[$caravanId];
                $catCode = strtoupper(trim((string) $caravan->getCategoryCode()));
                $targetCode = $this->resolveTargetCategoryCode($item);

                $fromCatId = $caravan->getCategoryId();

                $castrationId = $this->castrationRepository->save(
                    companyId: $dto->companyId,
                    caravanId: $caravanId,
                    castratedOn: $dto->castratedOn,
                    method: $dto->method,
                    notes: $dto->notes,
                    fromCategoryId: $fromCatId,
                    userId: $dto->userId
                );

                if ($catCode === 'TORITO' && $targetCode !== null) {
                    $targetCategory = $this->categoryRepository->findByCode($targetCode);
                    if ($targetCategory !== null && $targetCategory->getId() !== null) {
                        $this->recategorizer->change(
                            caravan: $caravan,
                            toCategoryId: $targetCategory->getId(),
                            toSubcategoryId: null,
                            cause: CategoryChangeCause::CASTRATION,
                            occurredOn: $dto->castratedOn,
                            source: CategoryChangeSource::CASTRATION,
                            sourceId: $castrationId,
                            userId: $dto->userId
                        );
                    }
                }

                $results[] = [
                    'id' => $castrationId,
                    'caravan_id' => $caravanId,
                    'castrated_on' => $dto->castratedOn,
                    'method' => $dto->method?->value,
                    'notes' => $dto->notes,
                    'from_category_id' => $fromCatId,
                ];
            }

            return $results;
        });
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveTargetCategoryCode(array $item): ?string
    {
        if (isset($item['target_category_code']) && trim((string) $item['target_category_code']) !== '') {
            return strtoupper(trim((string) $item['target_category_code']));
        }

        if (isset($item['target_category_id']) && !empty($item['target_category_id'])) {
            $cat = $this->categoryRepository->findById((int) $item['target_category_id']);
            if ($cat !== null && $cat->getCode() !== null) {
                return strtoupper(trim((string) $cat->getCode()));
            }
        }

        return null;
    }
}

