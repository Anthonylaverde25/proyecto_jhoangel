<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\VeterinaryDiagnosisMapper;
use App\Core\Entities\VeterinaryDiagnosisEntity;
use App\Core\Interfaces\IVeterinaryDiagnosisRepository;
use App\Models\VeterinaryDiagnosis;
use DateTimeImmutable;

class EloquentVeterinaryDiagnosisRepository implements IVeterinaryDiagnosisRepository
{
    public function save(VeterinaryDiagnosisEntity $diagnosis): VeterinaryDiagnosisEntity
    {
        $attributes = [
            'company_id' => $diagnosis->getCompanyId(),
            'caravan_id' => $diagnosis->getCaravanId(),
            'pathogen_id' => $diagnosis->getPathogenId(),
            'veterinarian_id' => $diagnosis->getVeterinarianId(),
            'diagnosis_date' => $diagnosis->getDiagnosisDate()->format('Y-m-d'),
            'status' => $diagnosis->getStatus()->value,
            'resolution_date' => $diagnosis->getResolutionDate()?->format('Y-m-d'),
            'treatment_notes' => $diagnosis->getTreatmentNotes(),
            'source_context' => $diagnosis->getSourceContext(),
        ];

        if ($diagnosis->getId()) {
            $model = VeterinaryDiagnosis::findOrFail($diagnosis->getId());
            $model->update($attributes);
        } else {
            $model = VeterinaryDiagnosis::create($attributes);
        }

        $model->load(['pathogen', 'veterinarian']);

        return VeterinaryDiagnosisMapper::toDomain($model);
    }

    public function findById(int $id): ?VeterinaryDiagnosisEntity
    {
        $model = VeterinaryDiagnosis::with(['pathogen', 'veterinarian'])->find($id);

        return $model ? VeterinaryDiagnosisMapper::toDomain($model) : null;
    }

    /**
     * @return array<VeterinaryDiagnosisEntity>
     */
    public function findByCaravanId(int $caravanId, bool $activeOnly = false): array
    {
        $query = VeterinaryDiagnosis::with(['pathogen', 'veterinarian'])
            ->where('caravan_id', $caravanId);

        if ($activeOnly) {
            $query->whereIn('status', ['CONFIRMED_POSITIVE', 'IN_TREATMENT']);
        }

        return $query->orderByDesc('diagnosis_date')
            ->get()
            ->map(fn (VeterinaryDiagnosis $model) => VeterinaryDiagnosisMapper::toDomain($model))
            ->all();
    }

    /**
     * @return array<VeterinaryDiagnosisEntity>
     */
    public function findActiveByCaravanId(int $caravanId): array
    {
        return $this->findByCaravanId($caravanId, true);
    }

    public function resolve(int $id, DateTimeImmutable $resolutionDate, ?string $notes = null): bool
    {
        $model = VeterinaryDiagnosis::find($id);
        if (!$model) {
            return false;
        }

        $updateData = [
            'status' => 'RESOLVED',
            'resolution_date' => $resolutionDate->format('Y-m-d'),
        ];

        if ($notes) {
            $existingNotes = $model->treatment_notes ?? '';
            $updateData['treatment_notes'] = trim($existingNotes . "\n[Alta]: " . $notes);
        }

        return $model->update($updateData);
    }
}
