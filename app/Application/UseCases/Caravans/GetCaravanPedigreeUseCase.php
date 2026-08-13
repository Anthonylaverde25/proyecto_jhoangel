<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Services\WrightInbreedingCalculator;
use App\Models\Caravan;
use App\Models\CaravanLineage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GetCaravanPedigreeUseCase
{
    public function __construct(
        private readonly WrightInbreedingCalculator $calculator
    ) {
    }

    /**
     * Executes the pedigree extraction, 3G tree construction, and inbreeding calculation for a caravan.
     *
     * @param int $caravanId
     * @return array<string, mixed>
     */
    public function __invoke(int $caravanId): array
    {
        $caravan = Caravan::with(['batch', 'currentWeight', 'breedRelation'])->find($caravanId);

        if (!$caravan) {
            throw new NotFoundHttpException("Caravana con ID {$caravanId} no encontrada.");
        }

        // Fetch all caravans with basic data and their lineage in a single fast query
        $rawCaravans = DB::select("
            SELECT 
                c.id, 
                c.identification, 
                c.category, 
                c.breed, 
                c.sex,
                l.father_id, 
                l.mother_id
            FROM caravans c
            LEFT JOIN caravan_lineage l ON c.id = l.caravan_id
        ");

        $caravansMap = [];
        foreach ($rawCaravans as $row) {
            $caravansMap[(int) $row->id] = [
                'id' => (int) $row->id,
                'identification' => $row->identification,
                'category' => $row->category,
                'breed' => $row->breed,
                'sex' => $row->sex,
                'father_id' => $row->father_id !== null ? (int) $row->father_id : null,
                'mother_id' => $row->mother_id !== null ? (int) $row->mother_id : null,
            ];
        }

        $myLineage = $caravansMap[$caravanId] ?? null;
        $fatherId = $myLineage['father_id'] ?? null;
        $motherId = $myLineage['mother_id'] ?? null;

        // Execute Inbreeding Calculation
        $inbreeding = $this->calculator->calculate($fatherId, $motherId, $caravansMap);

        // Helper to format an ancestor node
        $formatNode = function (?int $id, string $role, bool $isMale) use ($caravansMap): array {
            if ($id === null || !isset($caravansMap[$id])) {
                return [
                    'id' => null,
                    'identification' => null,
                    'category' => null,
                    'breed' => null,
                    'sex' => $isMale ? 'M' : 'H',
                    'role' => $role,
                    'is_unknown' => true,
                ];
            }
            $c = $caravansMap[$id];
            return [
                'id' => $c['id'],
                'identification' => $c['identification'],
                'category' => $c['category'],
                'breed' => $c['breed'],
                'sex' => $c['sex'] ?? ($isMale ? 'M' : 'H'),
                'role' => $role,
                'is_unknown' => false,
            ];
        };

        // Gen 1: Parents
        $father = $formatNode($fatherId, 'Padre (Toro ♂)', true);
        $mother = $formatNode($motherId, 'Madre (Vaca ♀)', false);

        // Gen 2: Grandparents
        $pgsId = $fatherId && isset($caravansMap[$fatherId]) ? $caravansMap[$fatherId]['father_id'] : null;
        $pgdId = $fatherId && isset($caravansMap[$fatherId]) ? $caravansMap[$fatherId]['mother_id'] : null;
        $mgsId = $motherId && isset($caravansMap[$motherId]) ? $caravansMap[$motherId]['father_id'] : null;
        $mgdId = $motherId && isset($caravansMap[$motherId]) ? $caravansMap[$motherId]['mother_id'] : null;

        $pgs = $formatNode($pgsId, 'Abuelo Pat. (PGS)', true);
        $pgd = $formatNode($pgdId, 'Abuela Pat. (PGD)', false);
        $mgs = $formatNode($mgsId, 'Abuelo Mat. (MGS)', true);
        $mgd = $formatNode($mgdId, 'Abuela Mat. (MGD)', false);

        // Gen 3: Great-Grandparents
        $ppsId = $pgsId && isset($caravansMap[$pgsId]) ? $caravansMap[$pgsId]['father_id'] : null;
        $ppdId = $pgsId && isset($caravansMap[$pgsId]) ? $caravansMap[$pgsId]['mother_id'] : null;
        $pmsId = $pgdId && isset($caravansMap[$pgdId]) ? $caravansMap[$pgdId]['father_id'] : null;
        $pmdId = $pgdId && isset($caravansMap[$pgdId]) ? $caravansMap[$pgdId]['mother_id'] : null;

        $mpsId = $mgsId && isset($caravansMap[$mgsId]) ? $caravansMap[$mgsId]['father_id'] : null;
        $mpdId = $mgsId && isset($caravansMap[$mgsId]) ? $caravansMap[$mgsId]['mother_id'] : null;
        $mmsId = $mgdId && isset($caravansMap[$mgdId]) ? $caravansMap[$mgdId]['father_id'] : null;
        $mmdId = $mgdId && isset($caravansMap[$mgdId]) ? $caravansMap[$mgdId]['mother_id'] : null;

        // Offspring
        $offspringRows = DB::select("
            SELECT 
                c.id, 
                c.identification, 
                c.category, 
                c.breed, 
                c.sex,
                l.father_id, 
                l.mother_id
            FROM caravan_lineage l
            INNER JOIN caravans c ON l.caravan_id = c.id
            WHERE l.father_id = :id1 OR l.mother_id = :id2
        ", ['id1' => $caravanId, 'id2' => $caravanId]);

        $offspring = [];
        foreach ($offspringRows as $o) {
            $mateId = (int) $o->father_id === $caravanId ? (int) $o->mother_id : (int) $o->father_id;
            $mate = $mateId && isset($caravansMap[$mateId]) ? $caravansMap[$mateId] : null;

            // Calculate child fx
            $childFx = $this->calculator->calculate(
                $o->father_id !== null ? (int) $o->father_id : null,
                $o->mother_id !== null ? (int) $o->mother_id : null,
                $caravansMap
            );

            $offspring[] = [
                'id' => (int) $o->id,
                'identification' => $o->identification,
                'category' => $o->category,
                'breed' => $o->breed,
                'sex' => $o->sex,
                'mate' => $mate ? [
                    'id' => $mate['id'],
                    'identification' => $mate['identification'],
                    'sex' => $mate['sex'],
                ] : null,
                'inbreeding_coefficient' => $childFx['fx'],
                'inbreeding_risk' => $childFx['risk'],
            ];
        }

        return [
            'caravan' => [
                'id' => $caravan->id,
                'identification' => $caravan->identification,
                'category' => $caravan->category?->value ?? (string) $caravan->category,
                'breed' => $caravan->breed,
                'sex' => $caravan->sex?->value ?? (string) $caravan->sex,
                'batch_name' => $caravan->batch?->name ?? 'General',
                'current_weight' => $caravan->currentWeight?->weight ? (float) $caravan->currentWeight->weight : null,
            ],
            'inbreeding' => $inbreeding,
            'tree' => [
                'father' => $father,
                'mother' => $mother,
                'pgs' => $pgs,
                'pgd' => $pgd,
                'mgs' => $mgs,
                'mgd' => $mgd,
                'pps' => $formatNode($ppsId, 'Bisabuelo PP', true),
                'ppd' => $formatNode($ppdId, 'Bisabuela PP', false),
                'pms' => $formatNode($pmsId, 'Bisabuelo PM', true),
                'pmd' => $formatNode($pmdId, 'Bisabuela PM', false),
                'mps' => $formatNode($mpsId, 'Bisabuelo MP', true),
                'mpd' => $formatNode($mpdId, 'Bisabuela MP', false),
                'mms' => $formatNode($mmsId, 'Bisabuelo MM', true),
                'mmd' => $formatNode($mmdId, 'Bisabuela MM', false),
            ],
            'offspring' => $offspring,
        ];
    }
}
