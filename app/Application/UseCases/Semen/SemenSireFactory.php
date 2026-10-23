<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Application\DTOs\Semen\SemenSireDTO;
use App\Application\Services\Stock\FieldErrorBag;
use App\Core\Entities\SemenSireEntity;
use App\Core\Exceptions\SemenException;
use App\Core\Interfaces\ISemenSireRepository;

/**
 * Builds a sire from what was typed, with every rule answered by field (plan part 3): an own bull is a
 * male caravan of the company, in any state (SS1, DA6), whose name and breed come from it (SS2); an
 * external bull needs its name; a registry repeated in the company is the same bull (SS5).
 */
final class SemenSireFactory
{
    public function __construct(
        private readonly ISemenSireRepository $sires,
    ) {
    }

    public function build(SemenSireDTO $dto, int $companyId, ?SemenSireEntity $current = null): SemenSireEntity
    {
        $errors = new FieldErrorBag();
        $registry = null;
        try {
            $registry = SemenSireEntity::cleanRegistry($dto->registryCode);
        } catch (SemenException $e) {
            $errors->add('registry_code', $e->getMessage());
        }
        if ($registry !== null) {
            $same = $this->sires->findByRegistry($registry, $companyId);
            if ($same !== null && $same->id !== $current?->id) {
                $errors->add('registry_code', "Ese registro ya es de {$same->label()}: elegilo en lugar de cargarlo de nuevo.");
            }
        }

        $caravanId = $current?->caravanId ?? $dto->caravanId;
        $name = '';
        $breedId = $dto->breedId;
        if ($caravanId !== null) {
            $bull = $this->sires->ownBull($caravanId, $companyId);
            if ($bull === null) {
                $errors->add('caravan_id', 'Elegí un macho de esta empresa.');
            } else {
                $name = $bull['identification'];
                $breedId = $bull['breed_id'];
                $taken = $this->sires->findByCaravan($caravanId, $companyId);
                if ($taken !== null && $taken->id !== $current?->id) {
                    $errors->add('caravan_id', "{$bull['identification']} ya es un padre de semen: elegilo en lugar de cargarlo de nuevo.");
                }
            }
        } else {
            try {
                $name = SemenSireEntity::cleanName((string) $dto->name);
            } catch (SemenException $e) {
                $errors->add('name', $e->getMessage());
            }
        }
        $errors->throwIfAny(SemenException::class);

        return new SemenSireEntity(
            $current?->id,
            $companyId,
            $name,
            $registry,
            $breedId,
            $dto->studName === null ? null : mb_substr($dto->studName, 0, 100),
            $caravanId,
            $dto->notes,
            $current?->isActive ?? true,
        );
    }
}
