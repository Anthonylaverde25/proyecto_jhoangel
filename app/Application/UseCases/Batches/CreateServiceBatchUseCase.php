<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Application\DTOs\CreateServiceBatchDTO;
use App\Application\Mappers\BatchMapper;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\ServiceBatchDetailEntity;
use App\Core\Entities\ServiceBatchEntity;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ServiceBatchDomainException;
use App\Core\Interfaces\IActivityRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\Company;
use App\Models\Farm;
use App\Models\ServiceBatchDetail;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderHistory;
use Illuminate\Support\Facades\DB;

final class CreateServiceBatchUseCase
{
    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly IBatchTypeRepository $batchTypeRepository,
        private readonly IActivityRepository $activityRepository,
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * @throws DomainException
     */
    public function __invoke(CreateServiceBatchDTO $dto): BatchEntity
    {
        if (trim($dto->name) === '') {
            throw ServiceBatchDomainException::domainError("El nombre del lote de servicio no puede estar vacío.");
        }

        // 1. Resolve 'SERVICE' BatchType
        $serviceBatchType = $this->batchTypeRepository->findByCode('SERVICE');
        if (!$serviceBatchType) {
            throw ServiceBatchDomainException::domainError("El tipo de lote 'SERVICE' no está configurado en el sistema.");
        }

        // 2. Resolve 'CRIA' Activity
        $criaActivity = $this->activityRepository->findByCode('CRIA');
        $activityId = $criaActivity?->getId();

        // 3. Resolve Company and Own Farm (if farm_id is null)
        $companyId = Company::first()?->id ?? 1;
        $farmId = $dto->farmId;
        if ($farmId === null) {
            $ownFarm = Farm::where('company_id', $companyId)->whereNull('provider_id')->first();
            $farmId = $ownFarm?->id;
        }

        // 4. Validate and construct the domain ServiceBatchEntity for invariant validation
        $tempBaseBatch = new BatchEntity(
            id: null,
            name: $dto->name,
            farmId: $farmId,
            observaciones: $dto->observaciones,
            isActive: true,
            activityId: $activityId,
            batchTypeId: $serviceBatchType->getId()
        );

        $femaleCategory = \App\Models\AnimalCategory::find($dto->femaleCategoryId);
        $maleCategory = \App\Models\AnimalCategory::find($dto->maleCategoryId);
        $femaleSubcategory = $dto->femaleSubcategoryId ? \App\Models\AnimalSubcategory::find($dto->femaleSubcategoryId) : null;

        $tempDetail = new ServiceBatchDetailEntity(
            id: null,
            batchId: 0,
            femaleCategoryId: $dto->femaleCategoryId,
            maleCategoryId: $dto->maleCategoryId,
            femaleSubcategoryId: $dto->femaleSubcategoryId,
            femaleCategoryName: $femaleCategory?->name,
            femaleCategoryCode: $femaleCategory?->code,
            femaleSubcategoryName: $femaleSubcategory?->name,
            femaleSubcategoryCode: $femaleSubcategory?->code,
            maleCategoryName: $maleCategory?->name,
            maleCategoryCode: $maleCategory?->code,
            targetBullRatio: $dto->targetBullRatio,
            plannedStartDate: $dto->plannedStartDate,
            plannedEndDate: $dto->plannedEndDate,
            notes: $dto->notes
        );

        $domainServiceBatch = new ServiceBatchEntity($tempBaseBatch, $tempDetail);

        // 5. Load and validate caravans
        $females = [];
        foreach ($dto->femaleCaravanIds as $femaleId) {
            $caravan = $this->caravanRepository->findById($femaleId);
            if (!$caravan) {
                throw ServiceBatchDomainException::domainError("La caravana hembra con ID {$femaleId} no existe.");
            }
            $domainServiceBatch->validateAnimalAdmission($caravan);
            $females[] = $caravan;
        }

        $males = [];
        foreach ($dto->maleCaravanIds as $maleId) {
            $caravan = $this->caravanRepository->findById($maleId);
            if (!$caravan) {
                throw ServiceBatchDomainException::domainError("La caravana reproductor macho con ID {$maleId} no existe.");
            }
            $domainServiceBatch->validateAnimalAdmission($caravan);
            $males[] = $caravan;
        }

        $savedBatchId = 0;

        // 6. Execute atomic creation and transfer
        DB::transaction(function () use (
            $dto,
            $serviceBatchType,
            $activityId,
            $farmId,
            $companyId,
            $females,
            $males,
            &$savedBatchId
        ) {
            // A. Create Batch Eloquent model
            $batch = Batch::create([
                'company_id' => $companyId,
                'name' => $dto->name,
                'farm_id' => $farmId,
                'activity_id' => $activityId,
                'batch_type_id' => $serviceBatchType->getId(),
                'observaciones' => $dto->observaciones,
                'is_active' => true,
                'is_system' => false,
            ]);

            $savedBatchId = $batch->id;

            // B. Create ServiceBatchDetail
            ServiceBatchDetail::create([
                'company_id' => $companyId,
                'batch_id' => $batch->id,
                'female_category_id' => $dto->femaleCategoryId,
                'female_subcategory_id' => $dto->femaleSubcategoryId,
                'male_category_id' => $dto->maleCategoryId,
                'target_bull_ratio' => $dto->targetBullRatio,
                'planned_start_date' => $dto->plannedStartDate,
                'planned_end_date' => $dto->plannedEndDate,
                'notes' => $dto->notes,
            ]);

            // C. Move Females and Log Movement
            foreach ($females as $female) {
                $originBatchId = $female->getBatchId();
                $female->setBatchId($batch->id);
                $this->caravanRepository->save($female);

                CaravanMovement::create([
                    'caravan_id' => $female->getId(),
                    'company_id' => $companyId,
                    'renspa' => $female->getRenspa() !== 'NO_DEFINIDO' ? $female->getRenspa() : 'NO_DEFINIDO',
                    'type' => 'TRANSFER',
                    'movement_date' => $dto->plannedStartDate ?? now()->toDateTimeString(),
                    'from_batch_id' => $originBatchId,
                    'to_batch_id' => $batch->id,
                    'observations' => "Ingreso a Lote de Servicio: '{$batch->name}'"
                ]);
            }

            // D. Move Males and Log Movement
            foreach ($males as $male) {
                $originBatchId = $male->getBatchId();
                $male->setBatchId($batch->id);
                $this->caravanRepository->save($male);

                CaravanMovement::create([
                    'caravan_id' => $male->getId(),
                    'company_id' => $companyId,
                    'renspa' => $male->getRenspa() !== 'NO_DEFINIDO' ? $male->getRenspa() : 'NO_DEFINIDO',
                    'type' => 'TRANSFER',
                    'movement_date' => $dto->plannedStartDate ?? now()->toDateTimeString(),
                    'from_batch_id' => $originBatchId,
                    'to_batch_id' => $batch->id,
                    'observations' => "Asignación de reproductor a Lote de Servicio: '{$batch->name}'"
                ]);
            }

            // E. Auto-create linked ServiceOrder if requested and animals are present
            if ($dto->autoCreateServiceOrder && (!empty($females) || !empty($males))) {
                $count = ServiceOrder::where('company_id', $companyId)->count() + 1;
                $serviceCode = 'OS-' . date('Y') . '-' . str_pad((string)$count, 3, '0', STR_PAD_LEFT);
                $serviceType = count($males) > 1 ? 'multi' : 'single';

                $serviceOrder = ServiceOrder::create([
                    'company_id' => $companyId,
                    'batch_id' => $batch->id,
                    'code' => $serviceCode,
                    'status' => 'APPROVED',
                    'service_type' => $serviceType,
                    'is_controlled_service' => false,
                    'planned_start_date' => $dto->plannedStartDate ?? now()->format('Y-m-d'),
                    'actual_start_date' => now()->format('Y-m-d'),
                    'approved_at' => now(),
                    'executed_at' => now(),
                    'observations' => $dto->observaciones ?? "Orden generada automáticamente con el Lote de Servicio: {$batch->name}"
                ]);

                if (!empty($males)) {
                    $malesPivot = [];
                    foreach ($males as $male) {
                        $malesPivot[$male->getId()] = ['company_id' => $companyId];
                    }
                    $serviceOrder->males()->attach($malesPivot);
                }

                if (!empty($females)) {
                    $femalesPivot = [];
                    foreach ($females as $female) {
                        $femalesPivot[$female->getId()] = ['company_id' => $companyId];
                    }
                    $serviceOrder->females()->attach($femalesPivot);
                }

                $userId = auth()->id() ?? \App\Models\User::first()?->id ?? 1;

                ServiceOrderHistory::create([
                    'company_id' => $companyId,
                    'service_order_id' => $serviceOrder->id,
                    'action_user_id' => $userId,
                    'from_status' => 'DRAFT',
                    'to_status' => 'APPROVED',
                    'action_reason' => "Orden de servicio inicializada automáticamente con la conformación del lote {$batch->name}"
                ]);
            }
        });

        $createdModel = Batch::with([
            'farm.provider',
            'batchType',
            'activity',
            'serviceDetail.femaleCategory',
            'serviceDetail.femaleSubcategory',
            'serviceDetail.maleCategory',
        ])->find($savedBatchId);

        if (!$createdModel) {
            throw ServiceBatchDomainException::domainError("Error recuperando el lote de servicio creado.");
        }

        return BatchMapper::toEntity($createdModel);
    }
}
