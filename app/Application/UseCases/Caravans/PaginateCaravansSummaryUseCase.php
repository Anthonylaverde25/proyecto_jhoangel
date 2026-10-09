<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Models\Caravan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PaginateCaravansSummaryUseCase
{
    /**
     * @param string $scope 'own' | 'external' | 'all'
     * @param int|string|null $batchId specific batch id, 'unassigned', or null for all
     * @param string|null $search search term for identification, breed, category
     * @param string|null $sex 'M' | 'H' | null
     * @param int $page current page number
     * @param int $perPage records per page
     */
    public function __invoke(
        string $scope = 'own',
        int|string|null $batchId = null,
        ?string $search = null,
        ?string $sex = null,
        int $page = 1,
        int $perPage = 25
    ): LengthAwarePaginator {
        $query = Caravan::query()
            ->with([
                'categoryRelation:id,name',
                'subcategoryRelation:id,name',
                'breedRelation:id,name',
                'batch:id,name,farm_id',
                'batch.farm:id,name',
                'currentWeight:id,caravan_id,weight,current',
            ]);

        // Scope filter (own vs external)
        if ($scope === 'own') {
            $query->where(function ($q) {
                $q->whereNull('batch_id')
                  ->orWhereHas('batch', function ($qb) {
                      $qb->where(function ($subQb) {
                          $subQb->whereNull('farm_id')
                                ->orWhereHas('farm', function ($farmQb) {
                                    $farmQb->whereNull('provider_id');
                                });
                      });
                  });
            });
        } elseif ($scope === 'external') {
            $query->where(function ($q) {
                $q->whereNotNull('provider_id')
                  ->orWhereHas('batch.farm', function ($farmQb) {
                      $farmQb->whereNotNull('provider_id');
                  });
            });
        }

        // Batch filter
        if ($batchId === 'unassigned' || $batchId === 0 || $batchId === '0') {
            $query->where(function ($q) {
                $q->whereNull('batch_id')->orWhere('batch_id', 0);
            });
        } elseif (is_numeric($batchId) && (int) $batchId > 0) {
            $query->where('batch_id', (int) $batchId);
        }

        // Sex filter
        if ($sex !== null && in_array(strtoupper($sex), ['M', 'H'], true)) {
            $query->where('sex', strtoupper($sex));
        }

        // Search term filter
        if (!empty($search)) {
            $term = trim($search);
            $query->where(function ($q) use ($term) {
                $q->where('identification', 'LIKE', "%{$term}%")
                  ->orWhereHas('breedRelation', fn ($bq) => $bq->where('name', 'LIKE', "%{$term}%"))
                  ->orWhereHas('categoryRelation', fn ($cq) => $cq->where('name', 'LIKE', "%{$term}%"));
            });
        }

        return $query->orderBy('identification')->paginate($perPage, ['*'], 'page', $page);
    }
}
