<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\CaravanEntity;
use App\Core\ValueObjects\AnimalLabel;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\ValueObjects\CaravanSummaryCriteria;
use App\Core\ValueObjects\CaravanSummaryRow;
use App\Core\ValueObjects\PaginatedResult;
use App\Models\Caravan;
use Illuminate\Support\Facades\DB;
use App\Application\Mappers\CaravanMapper;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\BreedingExclusionReason;
use App\Core\ValueObjects\BreedingEligibility;

class EloquentCaravanRepository implements ICaravanRepository
{
    public function save(CaravanEntity $caravan): CaravanEntity
    {
        $model = $caravan->getId() !== null ? Caravan::find($caravan->getId()) : null;
        $model = CaravanMapper::toModel($caravan, $model);
        $model->save();

        if ($caravan->getReproductiveDetails() !== null) {
            $details = $caravan->getReproductiveDetails();
            $model->femaleDetail()->updateOrCreate(
                ['caravan_id' => $model->id],
                [
                    'is_empty' => $details->isEmpty(),
                    'arrival_category' => $details->getArrivalCategory(),
                ]
            );
        } else {
            $model->femaleDetail()->delete();
        }

        // Persistir Gestaciones
        foreach ($caravan->getGestations() as $gestation) {
            $gestationModel = $model->gestations()->updateOrCreate(
                ['id' => $gestation->getId()],
                [
                    'start_date' => $gestation->getStartDate(),
                    'estimated_due_date' => $gestation->getEstimatedDueDate(),
                    'is_current' => $gestation->isCurrent(),
                    'gestation_stage' => $gestation->getGestationStage()?->value,
                    'gestation_months' => $gestation->getGestationMonths(),
                    'success' => $gestation->getSuccess(),
                    'loss_reason_id' => $gestation->getLossReasonId(),
                    'loss_notes' => $gestation->getLossNotes(),
                    'end_date' => $gestation->getEndDate(),
                    'notes' => $gestation->getNotes(),
                    'service_order_id' => $gestation->getServiceOrderId(),
                    'calving_overdue_reported_at' => $gestation->getCalvingOverdueReportedAt(),
                ]
            );

            // Sincronizar sires
            $sireSyncData = [];
            foreach ($gestation->getSires() as $sire) {
                $sireSyncData[$sire->getSireId()] = ['is_confirmed' => $sire->isConfirmed()];
            }
            $gestationModel->sires()->sync($sireSyncData);
        }

        return CaravanMapper::toEntity($model->load(['categoryRelation', 'subcategoryRelation', 'breedRelation', 'colorRelation', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father', 'death.cause']));
    }

    public function findByIdentification(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::with(['categoryRelation', 'subcategoryRelation', 'breedRelation', 'colorRelation', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father', 'death.cause'])
            ->where('identification', $identification->getValue())
            ->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findByIdentifications(array $identifications): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(static fn ($raw) => trim((string) $raw), $identifications),
            static fn (string $value): bool => $value !== ''
        )));

        if ($values === []) {
            return [];
        }

        $models = Caravan::with(['categoryRelation', 'subcategoryRelation', 'breedRelation', 'colorRelation', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father', 'death.cause'])
            ->whereIn('identification', $values)
            ->withCount(['offspringAsMother as nursing_calves_count' => fn ($q) => $q->where('is_nursing', true)])
            ->get();

        $resolved = [];

        foreach ($models as $model) {
            $resolved[mb_strtoupper(trim((string) $model->identification))] = CaravanMapper::toEntity($model);
        }

        return $resolved;
    }

    public function findOwnershipByIdentifications(array $identifications): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(static fn ($raw) => trim((string) $raw), $identifications),
            static fn (string $value): bool => $value !== ''
        )));

        if ($values === []) {
            return [];
        }

        $rows = Caravan::withoutGlobalScopes()
            ->leftJoin('batches', 'batches.id', '=', 'caravans.batch_id')
            ->whereIn('caravans.identification', $values)
            ->get([
                'caravans.id',
                'caravans.identification',
                'caravans.company_id',
                'caravans.batch_id',
                'batches.name as batch_name',
            ]);

        $resolved = [];

        foreach ($rows as $row) {
            $identification = (string) $row->identification;
            $resolved[$identification] = new \App\Core\ValueObjects\CaravanOwnership(
                (int) $row->id,
                $identification,
                (int) $row->company_id,
                $row->batch_id !== null ? (int) $row->batch_id : null,
                $row->batch_name !== null ? (string) $row->batch_name : null,
            );
        }

        return $resolved;
    }

    public function findByIdentificationGlobal(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::withoutGlobalScopes()
            ->with(['categoryRelation', 'subcategoryRelation', 'breedRelation', 'colorRelation', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father', 'death.cause'])
            ->where('identification', $identification->getValue())
            ->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findIdsInExternalBatches(array $caravanIds): array
    {
        if (empty($caravanIds)) {
            return [];
        }

        return Caravan::whereIn('id', $caravanIds)
            ->whereHas('batch', fn ($batchQb) => $batchQb->whereNotNull('farm_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function findById(int $id): ?CaravanEntity
    {
        $model = Caravan::with(['categoryRelation', 'subcategoryRelation', 'breedRelation', 'colorRelation', 'batch.farm.provider', 'provider', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father', 'death.cause'])
            ->withExists('openServiceExposures as is_in_service')
            ->withCount(['offspringAsMother as nursing_calves_count' => fn ($q) => $q->where('is_nursing', true)])
            ->find($id);
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function countOwn(): int
    {
        $query = Caravan::query();
        $this->applyOwnScope($query);

        return $query->count();
    }

    public function getDemographicsBreakdown(): array
    {
        $baseQuery = Caravan::query();
        $this->applyOwnScope($baseQuery);

        $sexRows = (clone $baseQuery)
            ->select('sex', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
            ->groupBy('sex')
            ->get();

        $total = 0;
        $femalesCount = 0;
        $malesCount = 0;

        foreach ($sexRows as $row) {
            $count = (int) $row->count;
            $total += $count;
            $val = $row->sex instanceof \BackedEnum ? $row->sex->value : (is_scalar($row->sex) ? (string) $row->sex : null);
            if ($val === AnimalSex::FEMALE->value || $val === 'H') {
                $femalesCount += $count;
            } elseif ($val === AnimalSex::MALE->value || $val === 'M') {
                $malesCount += $count;
            }
        }

        $catRows = (clone $baseQuery)
            ->leftJoin('animal_categories', 'caravans.category_id', '=', 'animal_categories.id')
            ->select(
                'caravans.sex',
                'animal_categories.id as category_id',
                'animal_categories.name as category_name',
                'animal_categories.code as category_code',
                \Illuminate\Support\Facades\DB::raw('count(*) as count')
            )
            ->groupBy('caravans.sex', 'animal_categories.id', 'animal_categories.name', 'animal_categories.code')
            ->orderByDesc('count')
            ->get();

        $femaleCategories = [];
        $maleCategories = [];

        foreach ($catRows as $row) {
            $count = (int) $row->count;
            $val = $row->sex instanceof \BackedEnum ? $row->sex->value : (is_scalar($row->sex) ? (string) $row->sex : null);
            $isFemale = ($val === AnimalSex::FEMALE->value || $val === 'H');

            $catItem = [
                'id' => $row->category_id ? (int) $row->category_id : 0,
                'name' => $row->category_name ?? ($isFemale ? 'Hembra sin categoría' : 'Macho sin categoría'),
                'code' => $row->category_code ?? 'S/C',
                'count' => $count,
                'percentage' => $isFemale
                    ? ($femalesCount > 0 ? round(($count / $femalesCount) * 100, 1) : 0.0)
                    : ($malesCount > 0 ? round(($count / $malesCount) * 100, 1) : 0.0),
            ];

            if ($isFemale) {
                $femaleCategories[] = $catItem;
            } else {
                $maleCategories[] = $catItem;
            }
        }

        return [
            'total' => $total,
            'by_sex' => [
                'females' => [
                    'count' => $femalesCount,
                    'percentage' => $total > 0 ? round(($femalesCount / $total) * 100, 1) : 0.0,
                    'categories' => $femaleCategories,
                ],
                'males' => [
                    'count' => $malesCount,
                    'percentage' => $total > 0 ? round(($malesCount / $total) * 100, 1) : 0.0,
                    'categories' => $maleCategories,
                ],
            ],
        ];
    }

    /**
     * Own animals: no batch, or an own batch (one without a farm: every farm is a provider's, D21).
     */
    private function applyOwnScope(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where(function ($q) {
            $q->whereNull('batch_id')
              ->orWhereHas('batch', fn ($qb) => $qb->whereNull('farm_id'));
        });
    }

    public function paginateSummary(CaravanSummaryCriteria $criteria): PaginatedResult
    {
        $query = Caravan::query()
            ->with([
                'categoryRelation:id,name,is_reproductive',
                'subcategoryRelation:id,name',
                'breedRelation:id,name',
                'batch:id,name,farm_id',
                'batch.farm:id,name',
                'currentWeight:id,caravan_id,weight,current',
                // An untagged calf is shown by its mother's tag.
                'lineage:id,caravan_id,mother_id',
                'lineage.mother:id,identification',
                'death:id,caravan_id,death_date,death_cause_id',
                'death.cause:id,name',
            ])
            ->withExists(['gestations as has_current_gestation' => fn ($q) => $q->where('is_current', true)]);

        if ($criteria->scope === 'own') {
            $this->applyOwnScope($query);
        } elseif ($criteria->scope === 'external') {
            $query->where(function ($q) {
                $q->whereNotNull('provider_id')
                  ->orWhereHas('batch', fn ($batchQb) => $batchQb->whereNotNull('farm_id'));
            });
        }

        if ($criteria->isUnassigned()) {
            $query->where(fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', 0));
        } elseif ($criteria->specificBatchId() !== null) {
            $query->where('batch_id', $criteria->specificBatchId());
        }

        if ($criteria->sex !== null) {
            $query->where('sex', $criteria->sex);
        }

        if ($criteria->categoryId !== null) {
            $query->where('category_id', $criteria->categoryId);
        }

        $term = trim((string) $criteria->search);
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('identification', 'LIKE', "%{$term}%")
                  // "4521" also finds "Cría de 4521": an untagged calf by its mother's tag.
                  ->orWhere(fn ($uq) => $uq->whereNull('identification')
                      ->whereHas('lineage.mother', fn ($mq) => $mq->where('identification', 'LIKE', "%{$term}%")))
                  ->orWhereHas('breedRelation', fn ($bq) => $bq->where('name', 'LIKE', "%{$term}%"))
                  ->orWhereHas('categoryRelation', fn ($cq) => $cq->where('name', 'LIKE', "%{$term}%"));
            });
        }

        if ($criteria->sort === CaravanSummaryCriteria::SORT_RECENT) {
            $query->orderByDesc('batch_assigned_at')->orderByDesc('id');
        } else {
            // Untagged calves go last (portable across MySQL and SQLite).
            $query->orderByRaw('identification IS NULL')->orderBy('identification');
        }

        $paginator = $query->paginate($criteria->perPage, ['*'], 'page', $criteria->page);

        return new PaginatedResult(
            items: array_map(fn (Caravan $model) => $this->toSummaryRow($model), $paginator->items()),
            total: $paginator->total(),
            page: $paginator->currentPage(),
            perPage: $paginator->perPage(),
        );
    }

    private function toSummaryRow(Caravan $model): CaravanSummaryRow
    {
        $currentWeight = $model->currentWeight?->weight ?? $model->entry_weight;
        $sex = $model->sex instanceof \BackedEnum ? (string) $model->sex->value : (string) $model->sex;
        $entryDate = $model->entry_date ?? $model->created_at;
        $isReproductiveCategory = $model->categoryRelation !== null ? (bool) $model->categoryRelation->is_reproductive : null;
        $breeds = $isReproductiveCategory !== false;

        return new CaravanSummaryRow(
            id: (int) $model->id,
            identification: $model->identification !== null ? (string) $model->identification : null,
            categoryId: $model->category_id,
            categoryName: $model->categoryRelation?->name,
            subcategoryId: $model->subcategory_id,
            subcategoryName: $model->subcategoryRelation?->name,
            teeth: $model->teeth,
            entryWeight: $model->entry_weight !== null ? (float) $model->entry_weight : null,
            currentWeight: $currentWeight !== null ? (float) $currentWeight : null,
            breedId: $model->breed_id,
            breedName: $model->breedRelation?->name,
            sex: $sex,
            entryDate: $entryDate?->format('m/Y'),
            batchId: $model->batch_id,
            batchName: $model->batch?->name,
            farmName: $model->batch?->farm?->name,
            batchAssignedAt: $model->batch_assigned_at !== null ? \DateTimeImmutable::createFromInterface($model->batch_assigned_at) : null,
            gestationStatus: $sex === AnimalSex::FEMALE->value && $breeds ? ($model->has_current_gestation ? 'PREGNANT' : 'NONE') : null,
            isBreedingEligible: (bool) ($model->is_breeding_eligible ?? true),
            breedingExclusionReason: $model->breeding_exclusion_reason,
            isReproductiveCategory: $isReproductiveCategory,
            label: AnimalLabel::of($model->identification, $model->lineage?->mother?->identification, $sex),
            deathDate: $model->death?->death_date?->format('Y-m-d'),
            deathCause: $model->death?->cause?->name,
        );
    }

    public function findAll(?string $scope = 'own'): array
    {
        $query = Caravan::with([
            'categoryRelation',
            'subcategoryRelation',
            'breedRelation',
            'colorRelation',
            'batch.farm.provider',
            'currentWeight',
            'femaleDetail',
            'gestations.sires', 'gestations.lossReason',
            'lineage.mother',
            'lineage.father',
            'death.cause',
            'provider',
        ])->withExists('openServiceExposures as is_in_service')
            ->withCount(['offspringAsMother as nursing_calves_count' => fn ($q) => $q->where('is_nursing', true)]);

        if ($scope === 'own') {
            $this->applyOwnScope($query);
        } elseif ($scope === 'external') {
            $query->where(function ($q) {
                $q->whereNotNull('provider_id')
                  ->orWhereHas('batch', fn ($batchQb) => $batchQb->whereNotNull('farm_id'));
            });
        }

        return $query->get()->map(fn($model) => CaravanMapper::toEntity($model))->toArray();
    }


    public function delete(int $id): bool
    {
        return (bool) Caravan::destroy($id);
    }

    public function findIdsInPossessionByBatch(int $batchId): array
    {
        return Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->orderBy('caravans.id')
            ->pluck('caravans.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function countByBatch(int $batchId): int
    {
        return Caravan::inPossession()->where('caravans.batch_id', $batchId)->count();
    }

    public function untaggedLabels(array $caravanIds): array
    {
        if ($caravanIds === []) {
            return [];
        }

        $labels = [];

        Caravan::query()
            ->with('lineage.mother:id,identification')
            ->whereIn('id', array_values(array_unique($caravanIds)))
            ->whereNull('identification')
            ->get(['id', 'sex'])
            ->each(function (Caravan $calf) use (&$labels): void {
                $labels[(int) $calf->id] = AnimalLabel::of(null, $calf->lineage?->mother?->identification, $calf->sex);
            });

        return $labels;
    }

    public function findUntaggedCalvesByBatch(int $batchId): array
    {
        return DB::table('caravans')
            ->join('caravan_lineage', 'caravan_lineage.caravan_id', '=', 'caravans.id')
            ->join('caravans as mothers', 'mothers.id', '=', 'caravan_lineage.mother_id')
            ->where('caravans.batch_id', $batchId)
            ->whereNull('caravans.identification')
            // A calf that died before being tagged is never tagged.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('caravan_deaths')->whereColumn('caravan_deaths.caravan_id', 'caravans.id'))
            ->orderBy('caravan_lineage.birth_date')
            ->orderBy('mothers.identification')
            ->get([
                'caravans.id',
                'caravans.sex',
                'caravans.entry_weight',
                'caravan_lineage.birth_date',
                'caravan_lineage.mother_id',
                'mothers.identification as mother_identification',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'label' => AnimalLabel::of(null, (string) $row->mother_identification, (string) $row->sex),
                'sex' => (string) $row->sex,
                'birth_date' => substr((string) $row->birth_date, 0, 10),
                'birth_weight' => $row->entry_weight !== null ? (float) $row->entry_weight : null,
                'mother_id' => (int) $row->mother_id,
                'mother_identification' => (string) $row->mother_identification,
            ])
            ->values()
            ->all();
    }

    public function countWeighedByBatch(int $batchId): int
    {
        return Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->count();
    }

    public function getTotalWeightByBatch(int $batchId): ?float
    {
        $sum = Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->sum('caravan_weights.weight');

        // `sum` returns 0 for an empty set, which would state that the batch holds zero
        // kilos of cattle. That is true for an empty batch and meaningless otherwise, so
        // the caller decides by looking at the weighed count.
        return $sum !== null ? (float) $sum : null;
    }

    public function getLatestWeighingDateByBatch(int $batchId): ?\DateTimeInterface
    {
        $date = Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->max('caravan_weights.weighing_date');

        return $date !== null ? new \DateTimeImmutable((string) $date) : null;
    }

    public function moveCaravansToBatch(
        array $caravanIds,
        int $targetBatchId,
        ?int $categoryId = null,
        ?int $subcategoryId = null
    ): array {
        if ($caravanIds === []) {
            return [];
        }

        // The source batches have to be read BEFORE the move: afterwards the previous
        // membership is gone and there is no way to know which batches to recalculate.
        $sourceBatchIds = Caravan::whereIn('id', $caravanIds)
            ->whereNotNull('batch_id')
            ->distinct()
            ->pluck('batch_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $data = ['batch_id' => $targetBatchId];

        if ($categoryId !== null) {
            $data['category_id'] = $categoryId;
        }

        if ($subcategoryId !== null) {
            $data['subcategory_id'] = $subcategoryId;
        }

        // Query builder writes skip the model observer: stamp the arrival here, only on the
        // caravans that actually change batch.
        Caravan::whereIn('id', $caravanIds)
            ->where(fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', '!=', $targetBatchId))
            ->update(['batch_assigned_at' => now()]);

        Caravan::whereIn('id', $caravanIds)->update($data);

        return array_values(array_unique([...$sourceBatchIds, $targetBatchId]));
    }

    public function getAverageWeightByBatch(int $batchId): ?float
    {
        $avg = Caravan::where('batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->avg('caravan_weights.weight');

        return $avg !== null ? (float) $avg : null;
    }

    public function getMinWeightByBatch(int $batchId): ?float
    {
        $min = Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->min('caravan_weights.weight');

        return $min !== null ? (float) $min : null;
    }

    public function getMaxWeightByBatch(int $batchId): ?float
    {
        $max = Caravan::inPossession()->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->max('caravan_weights.weight');

        return $max !== null ? (float) $max : null;
    }

    public function findBirthHistory(): array
    {
        $gestations = \App\Models\CaravanGestation::where('success', true)
            ->with(['caravan.batch', 'offspring.caravan.batch', 'offspring.caravan.death'])
            ->get();

        $nursingIds = $gestations
            ->flatMap(fn ($g) => $g->offspring)
            ->filter(fn ($lineage) => $lineage->caravan !== null && (bool) $lineage->is_nursing)
            ->map(fn ($lineage) => (int) $lineage->caravan_id)
            ->unique()
            ->values()
            ->all();
        $weights = $this->lastWeightsAfterBirth($nursingIds);

        $history = [];
        foreach ($gestations as $g) {
            foreach ($g->offspring as $lineage) {
                if ($lineage->caravan === null) {
                    continue;
                }

                $weight = $weights[(int) $lineage->caravan_id] ?? null;

                $history[] = new \App\Core\Entities\BirthHistoryEntity(
                    gestationId: (int) $g->id,
                    motherId: (int) $g->caravan_id,
                    motherIdentification: (string) ($g->caravan?->identification ?? ''),
                    birthDate: $g->end_date ? $g->end_date->format('Y-m-d') : '',
                    notes: $g->notes,
                    calfId: (int) $lineage->caravan_id,
                    calfIdentification: $lineage->caravan?->identification !== null ? (string) $lineage->caravan->identification : null,
                    isNursing: (bool) $lineage->is_nursing,
                    calfSex: $lineage->caravan?->sex?->value,
                    calfBatchName: $lineage->caravan?->batch?->name,
                    calfBatchId: $lineage->caravan?->batch_id !== null ? (int) $lineage->caravan->batch_id : null,
                    motherBatchId: $g->caravan?->batch_id !== null ? (int) $g->caravan->batch_id : null,
                    motherBatchName: $g->caravan?->batch?->name,
                    calfCategoryId: $lineage->caravan?->category_id !== null ? (int) $lineage->caravan->category_id : null,
                    calfSubcategoryId: $lineage->caravan?->subcategory_id !== null ? (int) $lineage->caravan->subcategory_id : null,
                    calfDeathDate: $lineage->caravan?->death?->death_date?->format('Y-m-d'),
                    calfWeight: $weight['weight'] ?? null,
                    calfWeighingDate: $weight['date'] ?? null
                );
            }
        }

        return $history;
    }

    /**
     * The last weight of each calf taken after it was born. The birth weighing (its type, or its
     * source on older rows) is left out: averaged with later ones it would only reflect how many
     * calves were never weighed again.
     *
     * @param list<int> $caravanIds
     * @return array<int, array{weight: float, date: ?string}>
     */
    private function lastWeightsAfterBirth(array $caravanIds): array
    {
        if ($caravanIds === []) {
            return [];
        }

        $birthTypeId = \App\Models\WeighingType::idOf(\App\Core\Enums\WeighingTypeCode::BIRTH);

        $rows = \App\Models\CaravanWeight::whereIn('caravan_id', $caravanIds)
            ->where(fn ($query) => $query->whereNull('source')->orWhere('source', '!=', \App\Core\Enums\WeighingSource::BIRTH->value))
            ->when($birthTypeId > 0, fn ($query) => $query->where(
                fn ($type) => $type->whereNull('weighing_type_id')->orWhere('weighing_type_id', '!=', $birthTypeId)
            ))
            ->orderByDesc('weighing_date')
            ->orderByDesc('id')
            ->get(['caravan_id', 'weight', 'weighing_date']);

        $last = [];
        foreach ($rows as $row) {
            $id = (int) $row->caravan_id;

            if (!isset($last[$id])) {
                $date = $row->weighing_date;
                $last[$id] = [
                    'weight' => (float) $row->weight,
                    'date' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : ($date !== null ? substr((string) $date, 0, 10) : null),
                ];
            }
        }

        return $last;
    }

    public function updateBatchAndCategory(int $caravanId, int $batchId, ?int $categoryId = null, ?int $subcategoryId = null): void
    {
        $data = ['batch_id' => $batchId];
        if ($categoryId !== null) {
            $data['category_id'] = $categoryId;
        }
        if ($subcategoryId !== null) {
            $data['subcategory_id'] = $subcategoryId;
        }
        $this->stampBatchArrival($caravanId, $batchId);
        Caravan::where('id', $caravanId)->update($data);
    }

    public function updateBatchAndReclassify(int $caravanId, int $batchId, int $categoryId, ?int $subcategoryId = null): void
    {
        $this->stampBatchArrival($caravanId, $batchId);
        Caravan::where('id', $caravanId)->update([
            'batch_id' => $batchId,
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
        ]);
    }

    /**
     * Query builder writes skip the model observer, so the arrival date is stamped here when the
     * caravan is about to change batch.
     */
    private function stampBatchArrival(int $caravanId, int $batchId): void
    {
        Caravan::where('id', $caravanId)
            ->where(fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', '!=', $batchId))
            ->update(['batch_assigned_at' => now()]);
    }

    public function updateTeeth(int $caravanId, int $teeth): void
    {
        Caravan::where('id', $caravanId)->update(['teeth' => $teeth]);
    }

    public function findGestatingByBatch(int $batchId): array
    {
        $models = Caravan::with(['breedRelation', 'batch', 'currentWeight', 'femaleDetail', 'gestations.sires', 'gestations.lossReason', 'lineage.mother', 'lineage.father', 'death.cause'])
            ->where('batch_id', $batchId)
            ->whereHas('gestations', function ($query) {
                $query->where('is_current', true);
            })
            ->get();
            
        return $models->map(fn($model) => CaravanMapper::toEntity($model))->toArray();
    }

    public function findServiceableFemaleIdsInBatch(int $batchId, bool $onlyBreedingEligible = false): array
    {
        return Caravan::inPossession()
            ->where('caravans.batch_id', $batchId)
            ->where('caravans.sex', AnimalSex::FEMALE->value)
            ->whereDoesntHave('gestations', fn ($q) => $q->where('is_current', true))
            ->whereDoesntHave('categoryRelation', fn ($q) => $q->where('is_reproductive', false))
            ->when($onlyBreedingEligible, fn ($q) => $q->where('caravans.is_breeding_eligible', true))
            ->pluck('caravans.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function findBreedingExcludedFemaleIdsInBatch(int $batchId): array
    {
        return Caravan::inPossession()
            ->where('caravans.batch_id', $batchId)
            ->where('caravans.sex', AnimalSex::FEMALE->value)
            ->where('caravans.is_breeding_eligible', false)
            ->whereDoesntHave('gestations', fn ($q) => $q->where('is_current', true))
            ->whereDoesntHave('categoryRelation', fn ($q) => $q->where('is_reproductive', false))
            ->pluck('caravans.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function findBreedingEligibility(int $caravanId): ?BreedingEligibility
    {
        $row = Caravan::withoutGlobalScopes()
            ->whereKey($caravanId)
            ->first(['is_breeding_eligible', 'breeding_exclusion_reason']);

        if ($row === null) {
            return null;
        }

        return new BreedingEligibility(
            (bool) $row->is_breeding_eligible,
            BreedingExclusionReason::tryFrom((string) $row->breeding_exclusion_reason)
        );
    }

    public function findCategoryBreedingProfile(int $caravanId): ?array
    {
        $category = Caravan::withoutGlobalScopes()
            ->whereKey($caravanId)
            ->with('categoryRelation:id,name,is_reproductive')
            ->first(['id', 'category_id'])
            ?->categoryRelation;

        return $category !== null ? ['name' => (string) $category->name, 'is_reproductive' => (bool) $category->is_reproductive] : null;
    }
}
