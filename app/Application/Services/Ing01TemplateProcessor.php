<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\WeighingSource;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Services\CaravanWeighingRecorder;
use App\Core\ValueObjects\WeighingReading;
use App\Application\DTOs\Ing01\Ing01SubmissionDTO;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\Company;
use App\Models\Farm;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class Ing01TemplateProcessor
{
    public function __construct(
        private readonly AnimalCategoryResolver $categoryResolver,
        private readonly BreedAndColorResolver $breedAndColorResolver,
        private readonly ActivityResolver $activityResolver,
        private readonly CaravanWeighingRecorder $weighingRecorder
    ) {
    }

    /**
     * Process and persist ING-01 payload atomically within a DB transaction.
     *
     * @param Ing01SubmissionDTO $dto
     * @return array<string, mixed>
     */
    public function process(Ing01SubmissionDTO $dto): array
    {
        return DB::transaction(function () use ($dto) {
            // 1. The company *is* its own farm (D21): own batches carry no farm.
            $company = Company::find($dto->companyId);

            // 2. Resolve Provider by CUIT or Name if provided
            $provider = $this->resolveProvider($dto->providerCuit, $dto->providerName, $dto->companyId);

            // 3. Resolve Activity if specified in DTO
            $activityResult = $this->activityResolver->resolve($dto->activity, $dto->companyId);

            // 4. Resolve Batch (Own Batch vs. External Provider Batch)
            $isExternalBatch = false;
            if (!empty($dto->batchName)) {
                // Scenario A: Own batch specified -> find or create it as an own batch (no farm)
                $batch = $this->resolveBatch($dto->batchName, $dto->companyId, null, $activityResult->activityId);
                $farm = null;
            } elseif (!empty($dto->providerBatchName)) {
                // Scenario B: Own batch empty, Provider batch specified -> Create on Provider Farm (External)
                $providerFarm = $this->resolveProviderFarm($provider, $dto->providerFarmName, $dto->providerRenspa, $dto->companyId);
                $batch = $this->resolveBatch($dto->providerBatchName, $dto->companyId, $providerFarm->id, $activityResult->activityId);
                $farm = $providerFarm;
                $isExternalBatch = true;
            } else {
                // Fallback: Default Own Batch
                $defaultBatchName = 'LOTE ING ' . date('Ymd-His');
                $batch = $this->resolveBatch($defaultBatchName, $dto->companyId, null, $activityResult->activityId);
                $farm = null;
            }

            $processedCaravans = [];
            $originRenspa = $dto->providerRenspa ?? $company?->renspa ?? 'NO_DEFINIDO';

            foreach ($dto->caravans as $item) {
                // 4. Resolve Category, Subcategory, and Sex using deterministic Parent-First resolver
                $categoryResult = $this->categoryResolver->resolve($item->category, $item->sex);

                // 5. Resolve Breed and Color from single cell text
                $phenotypeResult = $this->breedAndColorResolver->resolve($item->breed);

                $provenanceMetadata = array_filter([
                    'guia_dte' => $dto->guiaDte,
                    'source_template' => 'ING-01',
                    'raw_category_text' => $item->category,
                    'raw_breed_text' => $item->breed,
                    'resolved_category_code' => $categoryResult->categoryCode,
                    'resolved_subcategory_code' => $categoryResult->subcategoryCode,
                    'resolved_breed_name' => $phenotypeResult->breedName,
                    'resolved_color_name' => $phenotypeResult->colorName,
                    'resolved_activity_name' => $activityResult->activityName,
                    'resolved_activity_code' => $activityResult->activityCode,
                    'requires_review' => $categoryResult->requiresReview,
                    'observations' => $item->observations,
                ], fn ($val) => $val !== null && $val !== '');

                // 6. Look up existing active Caravan in the company or create new
                $caravan = Caravan::where('company_id', $dto->companyId)
                    ->where('identification', $item->identification)
                    ->first();

                if ($caravan) {
                    $caravan->update([
                        'batch_id' => $batch->id,
                        'provider_id' => $provider?->id ?? $caravan->provider_id,
                        'renspa' => $originRenspa !== 'NO_DEFINIDO' ? $originRenspa : $caravan->renspa,
                        'category_id' => $categoryResult->categoryId ?? $caravan->category_id,
                        'subcategory_id' => $categoryResult->subcategoryId ?? $caravan->subcategory_id,
                        'sex' => $categoryResult->sex ?? $caravan->sex,
                        'teeth' => $item->teeth ?? $caravan->teeth,
                        'breed_id' => $phenotypeResult->breedId ?? $caravan->breed_id,
                        'color_id' => $phenotypeResult->colorId ?? $caravan->color_id,
                        'entry_weight' => $item->entryWeight ?? $caravan->entry_weight,
                        'entry_date' => $dto->entryDate,
                        'provenance_metadata' => array_merge($caravan->provenance_metadata ?? [], $provenanceMetadata),
                    ]);
                } else {
                    $caravan = Caravan::create([
                        'company_id' => $dto->companyId,
                        'batch_id' => $batch->id,
                        'provider_id' => $provider?->id,
                        'renspa' => $originRenspa,
                        'identification' => $item->identification,
                        'category_id' => $categoryResult->categoryId,
                        'subcategory_id' => $categoryResult->subcategoryId,
                        'sex' => $categoryResult->sex ?? 'M',
                        'teeth' => $item->teeth ?? 0,
                        'breed_id' => $phenotypeResult->breedId,
                        'color_id' => $phenotypeResult->colorId,
                        'entry_weight' => $item->entryWeight,
                        'entry_date' => $dto->entryDate,
                        'provenance_metadata' => $provenanceMetadata,
                    ]);
                }

                // 7. Record Initial Weight in caravan_weights
                if ($item->entryWeight !== null && $item->entryWeight > 0) {
                    $this->weighingRecorder->record((int) $caravan->id, WeighingReading::typical(
                        (float) $item->entryWeight,
                        new \DateTimeImmutable((string) $dto->entryDate),
                        WeighingTypeCode::ENTRY,
                        WeighingSource::ENTRY,
                        'Pesaje inicial de ingreso (ING-01)'
                    ));
                }

                // 8. Record Movement in caravan_movements
                CaravanMovement::create([
                    'caravan_id' => $caravan->id,
                    'company_id' => $dto->companyId,
                    'to_batch_id' => $batch->id,
                    'provider_id' => $provider?->id,
                    'renspa' => $originRenspa,
                    'type' => 'ENTRY',
                    'movement_date' => $dto->entryDate,
                    'provenance_metadata' => $provenanceMetadata,
                    'observations' => $item->observations ?? 'Ingreso registrado vía plantilla ING-01',
                ]);

                $processedCaravans[] = [
                    'id' => $caravan->id,
                    'identification' => $caravan->identification,
                    'category_id' => $caravan->category_id,
                    'subcategory_id' => $caravan->subcategory_id,
                    'breed_id' => $caravan->breed_id,
                    'breed' => $phenotypeResult->breedName,
                    'color_id' => $caravan->color_id,
                    'color' => $phenotypeResult->colorName,
                    'teeth' => $caravan->teeth,
                    'sex' => $caravan->sex,
                    'entry_weight' => $caravan->entry_weight,
                    'is_category_resolved' => $categoryResult->isResolved,
                    'is_breed_resolved' => $phenotypeResult->isResolved,
                ];
            }

            // 9. Recalculate Batch Average Weight
            $avgWeight = Caravan::where('batch_id', $batch->id)
                ->whereNotNull('entry_weight')
                ->avg('entry_weight');

            if ($avgWeight !== null) {
                $batch->update(['current_weight' => round((float)$avgWeight, 2)]);
            }

            Log::info("Ing01TemplateProcessor: Processed {$batch->name} (" . ($isExternalBatch ? 'External' : 'Own') . ") with " . count($processedCaravans) . " caravans for company {$dto->companyId}");

            return [
                'status' => 'success',
                'batch' => [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'farm_id' => $batch->farm_id,
                    'farm_name' => $farm?->name ?? $company?->name,
                    'activity_id' => $batch->activity_id,
                    'activity_name' => $batch->activity?->name,
                    'activity_code' => $batch->activity?->code,
                    'is_external' => $isExternalBatch,
                    'current_weight' => $batch->current_weight,
                ],
                'total_processed' => count($processedCaravans),
                'caravans' => $processedCaravans,
            ];
        });
    }

    /**
     * Resolve or create the provider's origin farm for an external batch. Every farm belongs to a
     * provider of the same company (D21): without a provider, only an existing farm can be used.
     */
    private function resolveProviderFarm(?Provider $provider, ?string $farmName, ?string $renspa, int $companyId): Farm
    {
        $cleanFarmName = $farmName !== null && trim($farmName) !== '' ? trim($farmName) : null;
        $cleanRenspa = $renspa !== null && trim($renspa) !== '' ? trim($renspa) : null;

        $query = Farm::withoutGlobalScopes()->where('company_id', $companyId);
        if ($provider) {
            $query->where('provider_id', $provider->id);
        }

        $existing = $cleanFarmName !== null
            ? (clone $query)->where('name', 'LIKE', "%{$cleanFarmName}%")->first()
            : ($provider ? (clone $query)->first() : null);

        if ($existing) {
            if ($cleanRenspa !== null && $existing->renspa === null) {
                $existing->update(['renspa' => $cleanRenspa]);
            }
            return $existing;
        }

        if ($provider) {
            return Farm::create([
                'company_id'  => $companyId,
                'provider_id' => $provider->id,
                'name'        => $cleanFarmName ?? "Establecimiento {$provider->name}",
                'renspa'      => $cleanRenspa,
                'location'    => 'Origen Proveedor',
                'is_active'   => true,
            ]);
        }

        // No provider: fall back to any origin farm of the company; none means there is no origin to use.
        $genericFarm = (clone $query)->first();
        if ($genericFarm) {
            return $genericFarm;
        }

        throw new \App\Core\Exceptions\DomainException(
            'Para cargar el lote en el campo del proveedor, la planilla tiene que indicar el proveedor (CUIT o nombre).'
        );
    }

    /**
     * Resolve Provider by CUIT or Name.
     */
    private function resolveProvider(?string $cuit, ?string $name, int $companyId): ?Provider
    {
        if ($cuit !== null && trim($cuit) !== '') {
            $cleanCuit = preg_replace('/[^0-9]/', '', $cuit);
            if ($cleanCuit !== '' && strlen($cleanCuit) >= 8) {
                $provider = Provider::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->whereRaw("REPLACE(cuit, '-', '') = ?", [$cleanCuit])
                    ->first();
                if ($provider) {
                    return $provider;
                }
            }
        }

        if ($name !== null && trim($name) !== '') {
            $cleanName = trim($name);
            $provider = Provider::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('name', 'LIKE', "%{$cleanName}%")
                ->first();
            if ($provider) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Resolve or create the batch: an own batch when $farmId is null, an external one on that farm.
     */
    private function resolveBatch(string $batchName, int $companyId, ?int $farmId, ?int $activityId = null): Batch
    {
        $cleanBatchName = trim($batchName);
        if ($cleanBatchName === '') {
            $cleanBatchName = 'LOTE ING ' . date('Ymd-His');
        }

        $existing = Batch::where('company_id', $companyId)
            ->where('farm_id', $farmId)
            ->where('name', $cleanBatchName)
            ->first();

        if ($existing) {
            if ($activityId !== null && !$existing->activity_id) {
                $existing->update(['activity_id' => $activityId]);
            }
            return $existing;
        }

        $batchType = BatchType::where('code', 'OPERATIONAL')->first() ?? BatchType::first();

        return Batch::create([
            'company_id' => $companyId,
            'farm_id' => $farmId,
            'activity_id' => $activityId,
            'name' => $cleanBatchName,
            'is_active' => true,
            'batch_type_id' => $batchType?->id,
        ]);
    }
}
