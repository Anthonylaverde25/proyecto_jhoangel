<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\WeaningType;
use App\Core\Exceptions\MotherCalfSeparationException;
use App\Core\Interfaces\ICaravanLineageRepository;

/**
 * A mother and her calf at foot left in different places are no longer together: the calf is
 * weaned. Every movement asks this before moving, so a mother never stays "lactating" for a calf
 * that is elsewhere, and the weaning is recorded with the kind the user chose.
 */
final class MotherCalfSeparation
{
    public function __construct(
        private readonly ICaravanLineageRepository $lineageRepository
    ) {
    }

    /**
     * The calves the movement parts from their mothers.
     *
     * @param array<int, string> $placeByCaravanId where each moving animal ends: "batch:12", "new:RECRIA"…
     * @return list<array{calf_id: int, pair: string}>
     */
    public function separated(array $placeByCaravanId): array
    {
        $separated = [];

        foreach ($this->lineageRepository->nursingPairsOf(array_keys($placeByCaravanId)) as $pair) {
            $motherPlace = $placeByCaravanId[$pair['mother_id']] ?? self::batch($pair['mother_batch_id']);
            $calfPlace = $placeByCaravanId[$pair['calf_id']] ?? self::batch($pair['calf_batch_id']);

            if ($motherPlace !== $calfPlace) {
                $separated[$pair['calf_id']] = [
                    'calf_id' => $pair['calf_id'],
                    // "4521 y su cría 7790", or "4521 y su cría" while the calf waits for its tag.
                    'pair' => trim("{$pair['mother_identification']} y su cría " . ($pair['calf_identification'] ?? '')),
                ];
            }
        }

        return array_values($separated);
    }

    /**
     * Weans the calves the movement parts from their mothers, or asks how when nobody said.
     *
     * @param array<int, string> $placeByCaravanId
     * @return list<int> the calves weaned
     *
     * @throws MotherCalfSeparationException
     */
    public function weanSeparated(array $placeByCaravanId, string $date, ?WeaningType $type): array
    {
        $separated = $this->separated($placeByCaravanId);

        if ($separated === []) {
            return [];
        }

        if ($type === null) {
            throw MotherCalfSeparationException::forPairs(array_column($separated, 'pair'));
        }

        foreach ($separated as $entry) {
            $this->lineageRepository->wean($entry['calf_id'], substr($date, 0, 10), $type);
        }

        return array_column($separated, 'calf_id');
    }

    public static function batch(?int $batchId): string
    {
        return 'batch:' . ($batchId ?? 'none');
    }
}
