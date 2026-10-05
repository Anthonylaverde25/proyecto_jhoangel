<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\ValueObjects\EntryTroop;
use App\Models\AnimalCategory;
use App\Models\Breed;
use App\Models\Farm;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/**
 * The rules of a troop that need the catalogues. EntryTroop already guarantees what its own values
 * can tell (head and sex counts, age, weights, repeated breeds); this checks what they point to.
 */
final class EntryOrderValidator
{
    /**
     * @throws EntryOrderDomainException
     */
    public function assertValid(EntryTroop $troop, int $companyId): void
    {
        $this->assertOrigin($troop, $companyId);
        $this->assertCategory($troop);
        $this->assertBreeds($troop);
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

    private function assertCategory(EntryTroop $troop): void
    {
        if ($troop->categoryId === null) {
            return;
        }

        $category = AnimalCategory::find($troop->categoryId);

        if ($category === null) {
            throw EntryOrderDomainException::invalid('La categoría no existe.', 'CATEGORY_INVALID', 'category_id');
        }

        if ($troop->sexComposition !== null && !$troop->sexComposition->allowsCategorySex((string) $category->sex)) {
            throw EntryOrderDomainException::invalid(
                "La categoría {$category->name} no admite una tropa de {$troop->sexComposition->label()}.",
                'SEX_NOT_ALLOWED_BY_CATEGORY',
                'sex_composition'
            );
        }
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
