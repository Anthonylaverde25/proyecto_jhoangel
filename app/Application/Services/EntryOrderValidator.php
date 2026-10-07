<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\EntryOrderCategoryEntity;
use App\Core\Enums\SexComposition;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\ValueObjects\EntryTroop;
use App\Models\AnimalCategory;
use App\Models\Breed;
use App\Models\Farm;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/**
 * The rules of a troop that need the catalogues. EntryTroop already guarantees what its own values
 * can tell (head and sex counts, age, weights, repeated breeds and categories); this checks what
 * they point to.
 */
final class EntryOrderValidator
{
    /**
     * The troop, valid, with the name and sex of each category: what a reception in the same
     * operation ("Registrar ingreso") reads to tell each animal's category.
     *
     * @throws EntryOrderDomainException
     */
    public function assertValid(EntryTroop $troop, int $companyId): EntryTroop
    {
        $this->assertOrigin($troop, $companyId);
        $troop = $this->assertCategories($troop);
        $this->assertBreeds($troop);

        return $troop;
    }

    private function assertOrigin(EntryTroop $troop, int $companyId): void
    {
        $provider = Provider::find($troop->providerId);

        if ($provider === null || !$provider->is_active) {
            throw EntryOrderDomainException::invalid('El proveedor no existe o está inactivo.', 'PROVIDER_INVALID', 'provider_id');
        }

        $farm = Farm::withoutGlobalScopes()->where('company_id', $companyId)->find($troop->farmId);

        if ($farm === null || (int) $farm->provider_id !== $troop->providerId) {
            throw EntryOrderDomainException::invalid(
                "El establecimiento elegido no es de {$provider->name}.",
                'FARM_NOT_OF_PROVIDER',
                'farm_id'
            );
        }
    }

    /**
     * Each category exists and admits the declared sexes. A troop of one sex only holds categories
     * that admit it. A troop of both sexes holds categories of either, and its males and females
     * have to fit them: 30 Novillito are 30 males at least.
     */
    private function assertCategories(EntryTroop $troop): EntryTroop
    {
        if ($troop->categories === []) {
            return $troop;
        }

        $catalogue = AnimalCategory::whereIn('id', array_map(fn ($line) => $line->getCategoryId(), $troop->categories))
            ->get(['id', 'name', 'sex'])
            ->keyBy('id');
        $composition = $troop->sexComposition;
        $onlyOf = ['M' => 0, 'H' => 0];
        $admits = ['M' => false, 'H' => false];
        $described = [];

        foreach ($troop->categoriesByPosition() as $line) {
            $category = $catalogue->get($line->getCategoryId());

            if ($category === null) {
                throw EntryOrderDomainException::invalid("La categoría {$line->getPosition()} no existe.", 'CATEGORY_INVALID', 'categories');
            }

            $sex = strtoupper((string) $category->sex);
            $described[] = new EntryOrderCategoryEntity(
                id: $line->getId(),
                position: $line->getPosition(),
                categoryId: $line->getCategoryId(),
                headCount: $line->getHeadCount(),
                categoryName: (string) $category->name,
                categorySex: $sex
            );

            if ($composition !== null && $composition !== SexComposition::MIXED && !$composition->allowsCategorySex($sex)) {
                throw EntryOrderDomainException::invalid(
                    "La categoría {$category->name} no admite una tropa de {$composition->label()}.",
                    'SEX_NOT_ALLOWED_BY_CATEGORY',
                    'sex_composition'
                );
            }

            $admits['M'] = $admits['M'] || $sex !== 'H';
            $admits['H'] = $admits['H'] || $sex !== 'M';

            if (isset($onlyOf[$sex])) {
                $onlyOf[$sex] += (int) $line->getHeadCount();
            }
        }

        if ($composition !== SexComposition::MIXED) {
            return $troop->withCategories($described);
        }

        foreach (['M' => 'machos', 'H' => 'hembras'] as $sex => $word) {
            if (!$admits[$sex]) {
                throw EntryOrderDomainException::invalid(
                    "Ninguna de las categorías admite {$word}: la tropa no puede ser de ambos sexos.",
                    'SEX_NOT_ALLOWED_BY_CATEGORY',
                    'sex_composition'
                );
            }
        }

        foreach (['M' => [$troop->maleCount, 'machos', 'male_count'], 'H' => [$troop->femaleCount, 'hembras', 'female_count']] as $sex => [$declared, $word, $field]) {
            if ($declared !== null && $onlyOf[$sex] > $declared) {
                throw EntryOrderDomainException::invalid(
                    "Las categorías declaran {$onlyOf[$sex]} {$word}, pero la tropa trae {$declared}.",
                    'SEX_COUNTS_CONTRADICT_CATEGORIES',
                    $field
                );
            }
        }

        return $troop->withCategories($described);
    }

    private function assertBreeds(EntryTroop $troop): void
    {
        foreach ($troop->breeds as $line) {
            $breed = Breed::find($line->getBreedId());

            if ($breed === null) {
                throw EntryOrderDomainException::invalid("La raza {$line->getLetter()} no existe.", 'BREED_INVALID', 'breeds');
            }

            if ($line->getColorId() === null) {
                continue;
            }

            $allowed = DB::table('breed_color')
                ->where('breed_id', $breed->id)
                ->where('color_id', $line->getColorId())
                ->exists();

            if (!$allowed) {
                throw EntryOrderDomainException::invalid(
                    "El pelaje elegido no corresponde a la raza {$breed->name}.",
                    'COLOR_NOT_OF_BREED',
                    'breeds'
                );
            }
        }
    }
}
