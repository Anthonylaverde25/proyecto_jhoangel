<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Entities\EntryOrderCategoryEntity;
use App\Core\Entities\EntryOrderDestinationEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\DestinationSexScope;
use App\Core\Enums\SexComposition;
use App\Core\Exceptions\EntryOrderDomainException;

/**
 * Where the animals of an entry order go, declared by category line and sex: "calves, males →
 * batch A; calves, females → batch B; heifers → batch B". A received caravan already says its line
 * and its sex, so it is routed without asking anything more at the chute: to the rule of its sex,
 * else to the rule of its whole line, else to the order's transit batch.
 *
 * A line has one rule for the whole line or one per sex, never both. A rule per sex only makes
 * sense when the line admits both sexes and the troop brings both. What no rule covers needs the
 * transit batch.
 */
final readonly class EntryDestinationPlan
{
    /**
     * @param list<EntryOrderDestinationEntity> $rules
     */
    private function __construct(private array $rules)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<EntryOrderDestinationEntity> $rules
     */
    public static function of(array $rules): self
    {
        // By line, and within a line the whole line first, then males, then females.
        $rank = [DestinationSexScope::ALL->value => 0, DestinationSexScope::MALE->value => 1, DestinationSexScope::FEMALE->value => 2];
        usort($rules, fn (EntryOrderDestinationEntity $a, EntryOrderDestinationEntity $b) => [$a->getCategoryPosition(), $rank[$a->getSexScope()->value]]
            <=> [$b->getCategoryPosition(), $rank[$b->getSexScope()->value]]);

        return new self(array_values($rules));
    }

    /**
     * @return list<EntryOrderDestinationEntity>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /**
     * The own batches the rules send animals to, each once.
     *
     * @return list<int>
     */
    public function batchIds(): array
    {
        return array_values(array_unique(array_map(fn (EntryOrderDestinationEntity $r) => $r->getBatchId(), $this->rules)));
    }

    /**
     * The batch of an animal of this line and sex, or null when no rule takes it.
     */
    public function batchFor(int $categoryPosition, AnimalSex $sex): ?int
    {
        $whole = null;

        foreach ($this->rules as $rule) {
            if ($rule->getCategoryPosition() !== $categoryPosition) {
                continue;
            }

            if ($rule->getSexScope() === DestinationSexScope::of($sex)) {
                return $rule->getBatchId();
            }

            if ($rule->getSexScope() === DestinationSexScope::ALL) {
                $whole = $rule->getBatchId();
            }
        }

        return $whole;
    }

    /**
     * The rules that send animals to a batch.
     *
     * @return list<EntryOrderDestinationEntity>
     */
    public function rulesTo(int $batchId): array
    {
        return array_values(array_filter($this->rules, fn (EntryOrderDestinationEntity $r) => $r->getBatchId() === $batchId));
    }

    /**
     * Whether the animals of a line can go to two batches by sex: the line admits both and the
     * troop brings both.
     */
    public static function splittable(EntryOrderCategoryEntity $line, ?SexComposition $composition): bool
    {
        return $line->getCategorySex() === 'BOTH' && ($composition === SexComposition::MIXED || $composition === null);
    }

    /**
     * The sexes an animal of a line can have in this troop.
     *
     * @return list<AnimalSex>
     */
    public static function possibleSexes(EntryOrderCategoryEntity $line, ?SexComposition $composition): array
    {
        $bySex = match ($composition) {
            SexComposition::MALE => [AnimalSex::MALE],
            SexComposition::FEMALE => [AnimalSex::FEMALE],
            default => [AnimalSex::MALE, AnimalSex::FEMALE],
        };

        // Without the category's sex loaded, the troop's composition is all there is to go by.
        return $line->getCategorySex() === null
            ? $bySex
            : array_values(array_filter($bySex, fn (AnimalSex $sex) => $line->admits($sex)));
    }

    /**
     * The (line, sex) pairs the troop can bring that no rule takes: they go to the transit batch.
     *
     * @return list<array{position: int, sex: AnimalSex}>
     */
    public function uncovered(EntryTroop $troop): array
    {
        $uncovered = [];

        foreach ($troop->categoriesByPosition() as $position => $line) {
            foreach (self::possibleSexes($line, $troop->sexComposition) as $sex) {
                if ($this->batchFor($position, $sex) === null) {
                    $uncovered[] = ['position' => $position, 'sex' => $sex];
                }
            }
        }

        return $uncovered;
    }

    /**
     * Whether every animal the troop can bring has a rule. An order without categories yet has
     * nothing to cover, and is not covered either: it needs its transit batch.
     */
    public function coversAll(EntryTroop $troop): bool
    {
        return $troop->categories !== [] && $this->uncovered($troop) === [];
    }

    /**
     * Each rule points to a line of the troop, once per sex, and splits by sex only a line that
     * can be split.
     *
     * @throws EntryOrderDomainException
     */
    public function assertValid(EntryTroop $troop): void
    {
        $lines = $troop->categoriesByPosition();
        $seen = [];

        foreach ($this->rules as $rule) {
            $position = $rule->getCategoryPosition();
            $line = $lines[$position] ?? null;

            if ($line === null) {
                throw EntryOrderDomainException::invalid(
                    "El destino apunta a la categoría {$position}, que la orden no declara.",
                    'DESTINATION_CATEGORY_UNKNOWN',
                    'destinations'
                );
            }

            $scope = $rule->getSexScope();
            $name = $line->getCategoryName() ?? "la categoría {$position}";

            if (isset($seen[$position][$scope->value])) {
                throw EntryOrderDomainException::invalid(
                    "{$name} tiene dos destinos para " . ($scope === DestinationSexScope::ALL ? 'todos sus animales' : "los {$scope->label()}") . '.',
                    'DESTINATION_DUPLICATED',
                    'destinations'
                );
            }

            $seen[$position][$scope->value] = true;

            if ($scope !== DestinationSexScope::ALL && !self::splittable($line, $troop->sexComposition)) {
                throw EntryOrderDomainException::invalid(
                    "{$name} no se separa por sexo: la categoría o la tropa es de un solo sexo. Elegí un destino para toda la categoría.",
                    'DESTINATION_SEX_NOT_SPLITTABLE',
                    'destinations'
                );
            }
        }

        foreach ($seen as $position => $scopes) {
            if (isset($scopes[DestinationSexScope::ALL->value]) && count($scopes) > 1) {
                $name = $lines[$position]->getCategoryName() ?? "La categoría {$position}";

                throw EntryOrderDomainException::invalid(
                    "{$name} tiene un destino para toda la categoría y otro por sexo: elegí una de las dos formas.",
                    'DESTINATION_SCOPE_CONFLICT',
                    'destinations'
                );
            }
        }
    }

    /**
     * The plan adjusted to a rewritten troop: rules of a line that is gone are dropped, and a rule
     * per sex of a line that can no longer be split becomes the rule of the whole line when its sex
     * is the only one left, or is dropped otherwise.
     *
     * @return array{plan: self, dropped: list<EntryOrderDestinationEntity>}
     */
    public function adjustedTo(EntryTroop $troop): array
    {
        $lines = $troop->categoriesByPosition();
        $kept = [];
        $dropped = [];

        foreach ($this->rules as $rule) {
            $line = $lines[$rule->getCategoryPosition()] ?? null;

            if ($line === null) {
                $dropped[] = $rule;
                continue;
            }

            $scope = $rule->getSexScope();

            if ($scope === DestinationSexScope::ALL || self::splittable($line, $troop->sexComposition)) {
                $kept[] = $rule;
                continue;
            }

            $possible = self::possibleSexes($line, $troop->sexComposition);
            $wholeLineTaken = array_filter($kept, fn (EntryOrderDestinationEntity $k) => $k->getCategoryPosition() === $rule->getCategoryPosition());

            if (count($possible) === 1 && DestinationSexScope::of($possible[0]) === $scope && $wholeLineTaken === []) {
                $kept[] = $rule->withPosition($rule->getCategoryPosition(), DestinationSexScope::ALL);
            } else {
                $dropped[] = $rule;
            }
        }

        return ['plan' => self::of($kept), 'dropped' => $dropped];
    }

    /**
     * Whether two plans send the same animals to the same batches.
     */
    public function equals(self $other): bool
    {
        $key = fn (self $plan) => array_map(
            fn (EntryOrderDestinationEntity $r) => "{$r->getCategoryPosition()}:{$r->getSexScope()->value}:{$r->getBatchId()}",
            $plan->rules
        );

        return $key($this) === $key($other);
    }
}
