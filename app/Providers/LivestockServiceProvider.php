<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Interfaces\IActivityRepository;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\IBatchOverviewRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\Interfaces\IBirthOrderRepository;
use App\Core\Interfaces\IBreedRepository;
use App\Core\Interfaces\IBreedingEligibilityRepository;
use App\Core\Interfaces\ICaravanDeathRepository;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Core\Interfaces\ICaravanRegistrationSubmissionRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Core\Interfaces\IDeathCauseRepository;
use App\Core\Interfaces\IEntryOrderRepository;
use App\Core\Interfaces\IFarmRepository;
use App\Core\Interfaces\IFieldMappingResolver;
use App\Core\Interfaces\IPaternityRepository;
use App\Core\Interfaces\IServiceOrderBullHistoryRepository;
use App\Core\Interfaces\IProviderRepository;
use App\Core\Interfaces\IServiceOrderIncidentRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Interfaces\ITransferOrderRepository;
use App\Core\Interfaces\IWeaningOrderDeviationRepository;
use App\Core\Interfaces\IWeaningOrderRepository;
use App\Core\Interfaces\IWeighingOrderRepository;
use App\Core\Interfaces\IWeighingTypeRepository;
use App\Core\Interfaces\IWorkTemplateRepository;
use App\Core\Interfaces\IWorkdayRepository;
use App\Infrastructure\Observers\CaravanBatchAssignmentObserver;
use App\Infrastructure\Observers\CaravanMovementServiceExposureObserver;
use App\Infrastructure\Persistence\EloquentActivityRepository;
use App\Infrastructure\Persistence\EloquentAnimalCategoryRepository;
use App\Infrastructure\Persistence\EloquentBatchOverviewRepository;
use App\Infrastructure\Persistence\EloquentBatchRepository;
use App\Infrastructure\Persistence\EloquentBatchTypeRepository;
use App\Infrastructure\Persistence\EloquentBirthOrderRepository;
use App\Infrastructure\Persistence\EloquentBreedRepository;
use App\Infrastructure\Persistence\EloquentBreedingEligibilityRepository;
use App\Infrastructure\Persistence\EloquentCaravanDeathRepository;
use App\Infrastructure\Persistence\EloquentCaravanLineageRepository;
use App\Infrastructure\Persistence\EloquentCaravanMovementRepository;
use App\Infrastructure\Persistence\EloquentCaravanRegistrationSubmissionRepository;
use App\Infrastructure\Persistence\EloquentCaravanRepository;
use App\Infrastructure\Persistence\EloquentCaravanWeightRepository;
use App\Infrastructure\Persistence\EloquentDeathCauseRepository;
use App\Infrastructure\Persistence\EloquentEntryOrderRepository;
use App\Infrastructure\Persistence\EloquentFarmRepository;
use App\Infrastructure\Persistence\EloquentFieldMappingResolver;
use App\Infrastructure\Persistence\EloquentPaternityRepository;
use App\Infrastructure\Persistence\EloquentServiceOrderBullHistoryRepository;
use App\Infrastructure\Persistence\EloquentProviderRepository;
use App\Infrastructure\Persistence\EloquentServiceOrderIncidentRepository;
use App\Infrastructure\Persistence\EloquentServiceOrderRepository;
use App\Infrastructure\Persistence\EloquentTransferOrderRepository;
use App\Infrastructure\Persistence\EloquentWeaningOrderDeviationRepository;
use App\Infrastructure\Persistence\EloquentWeaningOrderRepository;
use App\Infrastructure\Persistence\EloquentWeighingOrderRepository;
use App\Infrastructure\Persistence\EloquentWeighingTypeRepository;
use App\Infrastructure\Persistence\EloquentWorkTemplateRepository;
use App\Infrastructure\Persistence\EloquentWorkdayRepository;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use Illuminate\Support\ServiceProvider;

final class LivestockServiceProvider extends ServiceProvider
{
    /**
     * All container bindings for the Livestock domain.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        IActivityRepository::class => EloquentActivityRepository::class,
        IAnimalCategoryRepository::class => EloquentAnimalCategoryRepository::class,
        IBatchOverviewRepository::class => EloquentBatchOverviewRepository::class,
        IBatchRepository::class => EloquentBatchRepository::class,
        IBatchTypeRepository::class => EloquentBatchTypeRepository::class,
        IBirthOrderRepository::class => EloquentBirthOrderRepository::class,
        IBreedRepository::class => EloquentBreedRepository::class,
        IBreedingEligibilityRepository::class => EloquentBreedingEligibilityRepository::class,
        ICaravanDeathRepository::class => EloquentCaravanDeathRepository::class,
        ICaravanLineageRepository::class => EloquentCaravanLineageRepository::class,
        ICaravanMovementRepository::class => EloquentCaravanMovementRepository::class,
        ICaravanRegistrationSubmissionRepository::class => EloquentCaravanRegistrationSubmissionRepository::class,
        ICaravanRepository::class => EloquentCaravanRepository::class,
        ICaravanWeightRepository::class => EloquentCaravanWeightRepository::class,
        IDeathCauseRepository::class => EloquentDeathCauseRepository::class,
        IEntryOrderRepository::class => EloquentEntryOrderRepository::class,
        IFarmRepository::class => EloquentFarmRepository::class,
        IFieldMappingResolver::class => EloquentFieldMappingResolver::class,
        IPaternityRepository::class => EloquentPaternityRepository::class,
        IProviderRepository::class => EloquentProviderRepository::class,
        IServiceOrderBullHistoryRepository::class => EloquentServiceOrderBullHistoryRepository::class,
        IServiceOrderIncidentRepository::class => EloquentServiceOrderIncidentRepository::class,
        IServiceOrderRepository::class => EloquentServiceOrderRepository::class,
        ITransferOrderRepository::class => EloquentTransferOrderRepository::class,
        IWeaningOrderDeviationRepository::class => EloquentWeaningOrderDeviationRepository::class,
        IWeaningOrderRepository::class => EloquentWeaningOrderRepository::class,
        IWeighingOrderRepository::class => EloquentWeighingOrderRepository::class,
        IWeighingTypeRepository::class => EloquentWeighingTypeRepository::class,
        IWorkTemplateRepository::class => EloquentWorkTemplateRepository::class,
        IWorkdayRepository::class => EloquentWorkdayRepository::class,
    ];

    /**
     * Bootstrap livestock domain services and observers.
     */
    public function boot(): void
    {
        CaravanMovement::observe(CaravanMovementServiceExposureObserver::class);
        Caravan::observe(CaravanBatchAssignmentObserver::class);
    }
}
