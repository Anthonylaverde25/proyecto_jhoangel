<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\SemenMapper;
use App\Core\Entities\SemenSireEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Interfaces\ISemenSireRepository;
use App\Models\Caravan;
use App\Models\SemenSire;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Every query filters by company explicitly (D15, layer 2). */
class EloquentSemenSireRepository implements ISemenSireRepository
{
    public function findAll(int $companyId, ?string $search, bool $includeInactive): array
    {
        $counts = $this->activeItemCounts($companyId);

        return $this->query($companyId)
            ->with(['breed', 'caravan'])
            ->when(!$includeInactive, fn (Builder $q) => $q->where('is_active', true))
            ->when($search !== null && $search !== '', function (Builder $q) use ($search) {
                $term = '%' . $search . '%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $term)->orWhere('registry_code', 'like', $term));
            })
            ->orderBy('name')
            ->get()
            ->map(fn (SemenSire $model) => SemenMapper::sireToEntity($model, $counts[(int) $model->id] ?? 0))
            ->all();
    }

    public function findById(int $id, int $companyId): ?SemenSireEntity
    {
        $model = $this->query($companyId)->with(['breed', 'caravan'])->find($id);

        return $model ? SemenMapper::sireToEntity($model, $this->activeItemCounts($companyId)[$id] ?? 0) : null;
    }

    public function findByRegistry(string $registryCode, int $companyId): ?SemenSireEntity
    {
        $model = $this->query($companyId)->with(['breed', 'caravan'])->where('registry_code', $registryCode)->first();

        return $model ? SemenMapper::sireToEntity($model) : null;
    }

    public function findByCaravan(int $caravanId, int $companyId): ?SemenSireEntity
    {
        $model = $this->query($companyId)->with(['breed', 'caravan'])->where('caravan_id', $caravanId)->first();

        return $model ? SemenMapper::sireToEntity($model) : null;
    }

    public function save(SemenSireEntity $sire): SemenSireEntity
    {
        $data = [
            'company_id' => $sire->companyId,
            'name' => $sire->name,
            'registry_code' => $sire->registryCode,
            'breed_id' => $sire->breedId,
            'stud_name' => $sire->studName,
            'caravan_id' => $sire->caravanId,
            'notes' => $sire->notes,
            'is_active' => $sire->isActive,
        ];
        if ($sire->id === null) {
            $model = SemenSire::withoutGlobalScopes()->create($data);
        } else {
            $model = $this->query($sire->companyId)->findOrFail($sire->id);
            $model->update($data);
        }

        return $this->findById((int) $model->id, $sire->companyId) ?? SemenMapper::sireToEntity($model);
    }

    public function hasActiveItems(int $sireId, int $companyId): bool
    {
        return ($this->activeItemCounts($companyId)[$sireId] ?? 0) > 0;
    }

    public function ownBull(int $caravanId, int $companyId): ?array
    {
        $caravan = Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('sex', AnimalSex::MALE->value)
            ->find($caravanId);

        return $caravan === null ? null : ['identification' => (string) $caravan->identification, 'breed_id' => $caravan->breed_id];
    }

    /** @return array<int, int> active semen items by sire id */
    private function activeItemCounts(int $companyId): array
    {
        return DB::table('item_semen_details as d')
            ->join('items as i', 'i.id', '=', 'd.item_id')
            ->where('d.company_id', $companyId)
            ->where('i.is_active', true)
            ->groupBy('d.semen_sire_id')
            ->selectRaw('d.semen_sire_id, COUNT(*) AS total')
            ->pluck('total', 'semen_sire_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function query(int $companyId): Builder
    {
        return SemenSire::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
