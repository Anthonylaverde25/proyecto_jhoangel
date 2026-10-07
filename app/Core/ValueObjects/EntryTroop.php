<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Entities\EntryOrderBreedEntity;
use App\Core\Entities\EntryOrderCategoryEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\SexComposition;
use App\Core\Enums\TroopCondition;
use App\Core\Exceptions\EntryOrderDomainException;

/**
 * What an entry order declares about the purchase: where the troop comes from, what it is and
 * how it is. Everything a draft can rewrite, as one value. A purchase may bring several categories,
 * each with its head; the order's head is their sum. No activity nor batch type: the
 * external batch only holds the purchase until its animals are assigned to an own batch, and that
 * one is classified when it is created.
 *
 * A draft only needs the origin: the rest may still be missing, and what is there is checked.
 * assertComplete() demands everything, and runs when the purchase is confirmed.
 *
 * The rules that need nothing but these values live here. The ones that need the catalogues
 * (category sex, breed colours, the provider's farms) live in EntryOrderValidator.
 */
final readonly class EntryTroop
{
    /** Sum of the head of its categories; null while any of them is not declared. */
    public ?int $headCount;

    /**
     * @param EntryOrderCategoryEntity[] $categories
     * @param EntryOrderBreedEntity[] $breeds
     *
     * @throws EntryOrderDomainException
     */
    public function __construct(
        public int $providerId,
        public int $farmId,
        public ?string $auctionNumber,
        public array $categories,
        public ?SexComposition $sexComposition,
        public ?int $maleCount,
        public ?int $femaleCount,
        public ?TroopCondition $condition,
        public ?int $ageMinMonths,
        public ?int $ageMaxMonths,
        public ?bool $knowsToEat,
        public ?bool $tickVaccinated,
        public ?float $shrinkPercent,
        public ?float $estimatedWeight,
        public ?float $minWeight,
        public ?float $maxWeight,
        public string $purchaseDate,
        public ?string $responsable,
        public ?string $observations,
        public array $breeds
    ) {
        $this->assertCategories();
        $this->headCount = self::sumOfHead($categories);
        $this->assertHeadAndSexes();
        $this->assertAge();
        $this->assertWeights();
        $this->assertBreeds();
    }

    /**
     * Everything a confirmed purchase declares. The first thing missing is reported on its field.
     *
     * @throws EntryOrderDomainException
     */
    public function assertComplete(): void
    {
        if ($this->categories === []) {
            throw EntryOrderDomainException::invalid('Declará al menos una categoría con sus cabezas. Hace falta para confirmar la compra.', 'TROOP_INCOMPLETE', 'categories');
        }

        foreach ($this->categoriesByPosition() as $line) {
            if ($line->getHeadCount() === null) {
                throw EntryOrderDomainException::invalid(
                    "Indicá las cabezas de la categoría {$line->getPosition()}. Hace falta para confirmar la compra.",
                    'TROOP_INCOMPLETE',
                    'categories'
                );
            }
        }

        $missing = [
            'sex_composition' => [$this->sexComposition, 'Indicá si la tropa es de machos, hembras o ambos.'],
            'condition' => [$this->condition, 'Indicá el estado de la tropa.'],
            'knows_to_eat' => [$this->knowsToEat, 'Indicá si la tropa sabe comer.'],
            'tick_vaccinated' => [$this->tickVaccinated, 'Indicá si la tropa está vacunada contra la garrapata.'],
            'estimated_weight' => [$this->estimatedWeight, 'Indicá el peso aproximado.'],
        ];

        foreach ($missing as $field => [$value, $message]) {
            if ($value === null) {
                throw EntryOrderDomainException::invalid($message . ' Hace falta para confirmar la compra.', 'TROOP_INCOMPLETE', $field);
            }
        }

        if ($this->breeds === []) {
            throw EntryOrderDomainException::invalid('Declará al menos una raza. Hace falta para confirmar la compra.', 'TROOP_INCOMPLETE', 'breeds');
        }
    }

    public function isComplete(): bool
    {
        try {
            $this->assertComplete();

            return true;
        } catch (EntryOrderDomainException) {
            return false;
        }
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

    /**
     * The same troop with these category lines: the same categories, described.
     *
     * @param EntryOrderCategoryEntity[] $categories
     *
     * @throws EntryOrderDomainException
     */
    public function withCategories(array $categories): self
    {
        return new self(
            providerId: $this->providerId,
            farmId: $this->farmId,
            auctionNumber: $this->auctionNumber,
            categories: $categories,
            sexComposition: $this->sexComposition,
            maleCount: $this->maleCount,
            femaleCount: $this->femaleCount,
            condition: $this->condition,
            ageMinMonths: $this->ageMinMonths,
            ageMaxMonths: $this->ageMaxMonths,
            knowsToEat: $this->knowsToEat,
            tickVaccinated: $this->tickVaccinated,
            shrinkPercent: $this->shrinkPercent,
            estimatedWeight: $this->estimatedWeight,
            minWeight: $this->minWeight,
            maxWeight: $this->maxWeight,
            purchaseDate: $this->purchaseDate,
            responsable: $this->responsable,
            observations: $this->observations,
            breeds: $this->breeds
        );
    }

    /**
     * @return EntryOrderCategoryEntity[] keyed by position
     */
    public function categoriesByPosition(): array
    {
        $byPosition = [];

        foreach ($this->categories as $line) {
            $byPosition[$line->getPosition()] = $line;
        }

        ksort($byPosition);

        return $byPosition;
    }

    /**
     * The category lines an animal of this sex can belong to. Needs the categories' sexes.
     *
     * @return EntryOrderCategoryEntity[] keyed by position
     */
    public function categoriesAdmitting(AnimalSex $sex): array
    {
        return array_filter($this->categoriesByPosition(), fn (EntryOrderCategoryEntity $line) => $line->admits($sex));
    }

    /**
     * Whether some animal's sex leaves more than one category possible, so its line has to say which
     * one: a category column on the ING-03 and on the manual reception. Needs the categories' sexes.
     */
    public function needsCategoryPerAnimal(): bool
    {
        $sexes = match ($this->sexComposition) {
            SexComposition::MALE => [AnimalSex::MALE],
            SexComposition::FEMALE => [AnimalSex::FEMALE],
            default => [AnimalSex::MALE, AnimalSex::FEMALE],
        };

        foreach ($sexes as $sex) {
            if (count($this->categoriesAdmitting($sex)) > 1) {
                return true;
            }
        }

        return false;
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

    /**
     * @param EntryOrderCategoryEntity[] $categories
     */
    private static function sumOfHead(array $categories): ?int
    {
        if ($categories === []) {
            return null;
        }

        $sum = 0;

        foreach ($categories as $line) {
            if ($line->getHeadCount() === null) {
                return null;
            }

            $sum += $line->getHeadCount();
        }

        return $sum;
    }

    private function assertCategories(): void
    {
        $positions = [];
        $ids = [];

        foreach ($this->categories as $line) {
            $positions[] = $line->getPosition();

            if (isset($ids[$line->getCategoryId()])) {
                throw EntryOrderDomainException::invalid(
                    'La misma categoría está declarada dos veces: sumá sus cabezas en un solo renglón.',
                    'CATEGORY_DUPLICATED',
                    'categories'
                );
            }

            $ids[$line->getCategoryId()] = true;

            if ($line->getHeadCount() !== null && $line->getHeadCount() < 1) {
                throw EntryOrderDomainException::invalid('Cada categoría lleva al menos una cabeza.', 'HEAD_COUNT_INVALID', 'categories');
            }
        }

        sort($positions);

        if ($positions !== [] && $positions !== range(1, count($positions))) {
            throw EntryOrderDomainException::invalid('Las categorías se numeran 1, 2, 3… sin saltos.', 'CATEGORY_POSITIONS_INVALID', 'categories');
        }
    }

    private function assertHeadAndSexes(): void
    {
        if ($this->sexComposition === null) {
            return;
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

        if ($this->headCount !== null && $this->maleCount + $this->femaleCount !== $this->headCount) {
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
        if ($this->estimatedWeight !== null && $this->estimatedWeight <= 0) {
            throw EntryOrderDomainException::invalid('El peso aproximado tiene que ser mayor que cero.', 'WEIGHT_INVALID', 'estimated_weight');
        }

        foreach (['min_weight' => $this->minWeight, 'max_weight' => $this->maxWeight] as $field => $weight) {
            if ($weight !== null && $weight <= 0) {
                throw EntryOrderDomainException::invalid('Los pesos tienen que ser mayores que cero.', 'WEIGHT_INVALID', $field);
            }
        }

        if ($this->minWeight !== null && $this->estimatedWeight !== null && $this->minWeight > $this->estimatedWeight) {
            throw EntryOrderDomainException::invalid('El peso mínimo no puede superar al aproximado.', 'WEIGHT_RANGE_INVALID', 'min_weight');
        }

        if ($this->maxWeight !== null && $this->estimatedWeight !== null && $this->maxWeight < $this->estimatedWeight) {
            throw EntryOrderDomainException::invalid('El peso máximo no puede ser menor que el aproximado.', 'WEIGHT_RANGE_INVALID', 'max_weight');
        }

        if ($this->shrinkPercent !== null && ($this->shrinkPercent < 0 || $this->shrinkPercent >= 100)) {
            throw EntryOrderDomainException::invalid('El desbaste es un porcentaje entre 0 y 100.', 'SHRINK_INVALID', 'shrink_percent');
        }
    }

    private function assertBreeds(): void
    {
        if ($this->breeds === []) {
            return;
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
