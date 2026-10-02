<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Entities\EntryOrderBreedEntity;
use App\Core\Enums\SexComposition;
use App\Core\Enums\TroopCondition;
use App\Core\Exceptions\EntryOrderDomainException;

/**
 * What an entry order declares about the purchase: where the troop comes from, what it is and
 * how it is. Everything a draft can rewrite, as one value. No activity nor batch type: the
 * external batch only holds the purchase until its animals are assigned to an own batch, and that
 * one is classified when it is created.
 *
 * The rules that need nothing but these values live here. The ones that need the catalogues
 * (category sex, breed colours, the provider's farms) live in EntryOrderValidator.
 */
final readonly class EntryTroop
{
    /**
     * @param EntryOrderBreedEntity[] $breeds
     *
     * @throws EntryOrderDomainException
     */
    public function __construct(
        public int $providerId,
        public int $farmId,
        public ?string $auctionNumber,
        public int $headCount,
        public int $categoryId,
        public SexComposition $sexComposition,
        public ?int $maleCount,
        public ?int $femaleCount,
        public TroopCondition $condition,
        public ?int $ageMinMonths,
        public ?int $ageMaxMonths,
        public bool $knowsToEat,
        public bool $tickVaccinated,
        public ?float $shrinkPercent,
        public float $estimatedWeight,
        public ?float $minWeight,
        public ?float $maxWeight,
        public string $purchaseDate,
        public ?string $responsable,
        public ?string $observations,
        public array $breeds
    ) {
        $this->assertHeadAndSexes();
        $this->assertAge();
        $this->assertWeights();
        $this->assertBreeds();
    }

    /**
     * @return EntryOrderBreedEntity[] keyed by position
     */
    public function breedsByPosition(): array
    {
        $byPosition = [];

        foreach ($this->breeds as $breed) {
            $byPosition[$breed->getPosition()] = $breed;
        }

        ksort($byPosition);

        return $byPosition;
    }

    public function hasSeveralBreeds(): bool
    {
        return count($this->breeds) > 1;
    }

    /**
     * "9/10", or null when no age range was declared.
     */
    public function ageRangeLabel(): ?string
    {
        return $this->ageMinMonths !== null && $this->ageMaxMonths !== null
            ? "{$this->ageMinMonths}/{$this->ageMaxMonths}"
            : null;
    }

    private function assertHeadAndSexes(): void
    {
        if ($this->headCount < 1) {
            throw EntryOrderDomainException::invalid('La orden tiene que tener al menos una cabeza.', 'HEAD_COUNT_INVALID', 'head_count');
        }

        if ($this->sexComposition !== SexComposition::MIXED) {
            if ($this->maleCount !== null || $this->femaleCount !== null) {
                throw EntryOrderDomainException::invalid(
                    'Las cantidades de machos y hembras sólo se declaran cuando la tropa es de ambos sexos.',
                    'SEX_COUNTS_NOT_EXPECTED',
                    'male_count'
                );
            }

            return;
        }

        if ($this->maleCount === null || $this->femaleCount === null || $this->maleCount < 1 || $this->femaleCount < 1) {
            throw EntryOrderDomainException::invalid(
                'Una tropa de ambos sexos declara cuántos machos y cuántas hembras trae, al menos uno de cada uno.',
                'SEX_COUNTS_MISSING',
                'male_count'
            );
        }

        if ($this->maleCount + $this->femaleCount !== $this->headCount) {
            throw EntryOrderDomainException::invalid(
                "Machos ({$this->maleCount}) y hembras ({$this->femaleCount}) suman " . ($this->maleCount + $this->femaleCount)
                    . ", pero la orden es por {$this->headCount} cabezas.",
                'SEX_COUNTS_MISMATCH',
                'male_count'
            );
        }
    }

    private function assertAge(): void
    {
        if (($this->ageMinMonths === null) !== ($this->ageMaxMonths === null)) {
            throw EntryOrderDomainException::invalid(
                'El rango de edad lleva los dos extremos (por ejemplo, 9/10 meses), o ninguno.',
                'AGE_RANGE_INCOMPLETE',
                'age_min_months'
            );
        }

        if ($this->ageMinMonths !== null && $this->ageMinMonths > $this->ageMaxMonths) {
            throw EntryOrderDomainException::invalid(
                "La edad mínima ({$this->ageMinMonths}) no puede ser mayor que la máxima ({$this->ageMaxMonths}).",
                'AGE_RANGE_INVERTED',
                'age_max_months'
            );
        }
    }

    private function assertWeights(): void
    {
        if ($this->estimatedWeight <= 0) {
            throw EntryOrderDomainException::invalid('El peso aproximado tiene que ser mayor que cero.', 'WEIGHT_INVALID', 'estimated_weight');
        }

        foreach (['min_weight' => $this->minWeight, 'max_weight' => $this->maxWeight] as $field => $weight) {
            if ($weight !== null && $weight <= 0) {
                throw EntryOrderDomainException::invalid('Los pesos tienen que ser mayores que cero.', 'WEIGHT_INVALID', $field);
            }
        }

        if ($this->minWeight !== null && $this->minWeight > $this->estimatedWeight) {
            throw EntryOrderDomainException::invalid('El peso mínimo no puede superar al aproximado.', 'WEIGHT_RANGE_INVALID', 'min_weight');
        }

        if ($this->maxWeight !== null && $this->maxWeight < $this->estimatedWeight) {
            throw EntryOrderDomainException::invalid('El peso máximo no puede ser menor que el aproximado.', 'WEIGHT_RANGE_INVALID', 'max_weight');
        }

        if ($this->shrinkPercent !== null && ($this->shrinkPercent < 0 || $this->shrinkPercent >= 100)) {
            throw EntryOrderDomainException::invalid('El desbaste es un porcentaje entre 0 y 100.', 'SHRINK_INVALID', 'shrink_percent');
        }
    }

    private function assertBreeds(): void
    {
        if ($this->breeds === []) {
            throw EntryOrderDomainException::invalid('Declará al menos una raza.', 'BREEDS_MISSING', 'breeds');
        }

        $positions = [];
        $pairs = [];

        foreach ($this->breeds as $breed) {
            $positions[] = $breed->getPosition();
            $pair = $breed->getBreedId() . ':' . ($breed->getColorId() ?? '-');

            if (isset($pairs[$pair])) {
                throw EntryOrderDomainException::invalid(
                    'La misma raza con el mismo pelaje está declarada dos veces.',
                    'BREED_DUPLICATED',
                    'breeds'
                );
            }

            $pairs[$pair] = true;
        }

        sort($positions);

        if ($positions !== range(1, count($positions))) {
            throw EntryOrderDomainException::invalid('Las razas se numeran 1, 2, 3… sin saltos.', 'BREED_POSITIONS_INVALID', 'breeds');
        }
    }
}
