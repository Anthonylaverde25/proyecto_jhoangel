<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Interfaces\ICaravanRepository;

/**
 * The calves at foot of the cows a load is about to take (P1), so the screen can ask "Sale con
 * ella / Se queda" before confirming instead of answering it with a 422.
 */
final class ListCalvesAtFootUseCase
{
    public function __construct(
        private readonly ICaravanLineageRepository $lineageRepository,
        private readonly ICaravanRepository $caravanRepository,
    ) {
    }

    /**
     * @param list<int> $caravanIds
     * @return list<array{mother_id: int, calf_id: int, calf_label: string}>
     */
    public function __invoke(array $caravanIds): array
    {
        $pairs = array_values(array_filter(
            $this->lineageRepository->nursingPairsOf($caravanIds),
            fn (array $pair) => in_array($pair['mother_id'], $caravanIds, true)
        ));

        // Only the mothers of the active company: the lineage query itself is not scoped.
        $own = [];
        foreach (array_unique(array_column($pairs, 'mother_id')) as $motherId) {
            $own[$motherId] = $this->caravanRepository->findById($motherId) !== null;
        }

        $untagged = $this->caravanRepository->untaggedLabels(array_column($pairs, 'calf_id'));

        return array_values(array_map(fn (array $pair) => [
            'mother_id' => $pair['mother_id'],
            'calf_id' => $pair['calf_id'],
            'calf_label' => $pair['calf_identification'] ?? $untagged[$pair['calf_id']] ?? "Cría de {$pair['mother_identification']}",
        ], array_filter($pairs, fn (array $pair) => $own[$pair['mother_id']])));
    }
}
