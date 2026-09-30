<?php

declare(strict_types=1);

namespace App\Application\DTOs\Cact01;

/**
 * What the review screen of a CACT-01 load is told about its source batch.
 *
 * A proposal, never a decision: the screen shows it on the source batch cell and the operator
 * confirms it. `basis` says what backs it:
 *
 * - name_and_caravans: the written name and the animals agree.
 * - name: the written name matched and the animals said nothing either way.
 * - caravans: the written name matched nothing (a misread "CRIO"), the animals point to one batch.
 * - conflict: the name matched one batch and the animals are in another. Nothing is proposed;
 *   both are returned so the operator picks.
 * - none: nothing to go on.
 *
 * `animals` is what the system knows about each tag found, for the cells the scan left blank.
 */
final class Cact01SourceBatchResolutionDTO
{
    public const BASIS_NAME_AND_CARAVANS = 'name_and_caravans';
    public const BASIS_NAME = 'name';
    public const BASIS_CARAVANS = 'caravans';
    public const BASIS_CONFLICT = 'conflict';
    public const BASIS_NONE = 'none';

    /**
     * @param array{id: int, name: string}|null $proposed
     * @param array{id: int, name: string}|null $nameMatch the batch the written name matched
     * @param array{id: int, name: string}|null $caravansMatch the batch holding most animals read
     * @param list<array{id: int, name: string, count: int}> $distribution
     * @param list<array{identification: string, sex: string, category_label: ?string}> $animals
     */
    public function __construct(
        public readonly string $basis,
        public readonly ?array $proposed,
        public readonly ?array $nameMatch,
        public readonly ?array $caravansMatch,
        public readonly int $caravansRead,
        public readonly int $caravansFound,
        public readonly int $caravansInMatch,
        public readonly array $distribution,
        public readonly array $animals = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'basis' => $this->basis,
            'proposed' => $this->proposed,
            'name_match' => $this->nameMatch,
            'caravans_match' => $this->caravansMatch,
            'caravans_read' => $this->caravansRead,
            'caravans_found' => $this->caravansFound,
            'caravans_in_match' => $this->caravansInMatch,
            'caravans_not_found' => $this->caravansRead - $this->caravansFound,
            'distribution' => $this->distribution,
            'animals' => $this->animals,
        ];
    }
}
