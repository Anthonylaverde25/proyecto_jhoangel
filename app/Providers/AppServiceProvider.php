<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IFieldMappingResolver;
use App\Core\Interfaces\IWorkdayRepository;
use App\Infrastructure\Persistence\EloquentCaravanRepository;
use App\Infrastructure\Persistence\EloquentFieldMappingResolver;
use App\Infrastructure\Persistence\EloquentWorkdayRepository;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\Interfaces\IBreedRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\Interfaces\IProviderRepository;
use App\Core\Interfaces\IFarmRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Infrastructure\Persistence\EloquentProviderRepository;
use App\Infrastructure\Persistence\EloquentFarmRepository;
use App\Infrastructure\Persistence\EloquentBatchRepository;
use App\Infrastructure\Persistence\EloquentCaravanLineageRepository;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Infrastructure\Persistence\EloquentCaravanMovementRepository;
use App\Core\Interfaces\IActivityRepository;
use App\Infrastructure\Persistence\EloquentActivityRepository;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Infrastructure\Persistence\EloquentCaravanWeightRepository;
use App\Core\Interfaces\IWorkTemplateRepository;
use App\Infrastructure\Persistence\EloquentWorkTemplateRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Infrastructure\Persistence\EloquentServiceOrderRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ICompanyContext::class, \App\Core\Contexts\CompanyContext::class);
        $this->app->bind(ICaravanRepository::class, EloquentCaravanRepository::class);
        $this->app->bind(
            \App\Core\Interfaces\ICaravanRegistrationSubmissionRepository::class,
            \App\Infrastructure\Persistence\EloquentCaravanRegistrationSubmissionRepository::class
        );
        $this->app->bind(ICaravanLineageRepository::class, EloquentCaravanLineageRepository::class);
        $this->app->bind(ICaravanWeightRepository::class, EloquentCaravanWeightRepository::class);
        $this->app->bind(IFieldMappingResolver::class, EloquentFieldMappingResolver::class);
        $this->app->bind(IWorkdayRepository::class, EloquentWorkdayRepository::class);
        $this->app->bind(IProviderRepository::class, EloquentProviderRepository::class);
        $this->app->bind(IFarmRepository::class, EloquentFarmRepository::class);
        $this->app->bind(IBatchRepository::class, EloquentBatchRepository::class);
        $this->app->bind(ICompanyRepository::class, \App\Infrastructure\Persistence\EloquentCompanyRepository::class);
        $this->app->bind(IBreedRepository::class, \App\Infrastructure\Persistence\EloquentBreedRepository::class);
        $this->app->bind(ICaravanMovementRepository::class, EloquentCaravanMovementRepository::class);
        $this->app->bind(IActivityRepository::class, EloquentActivityRepository::class);
        $this->app->bind(IWorkTemplateRepository::class, EloquentWorkTemplateRepository::class);
        $this->app->bind(IBatchTypeRepository::class, \App\Infrastructure\Persistence\EloquentBatchTypeRepository::class);
        $this->app->bind(IServiceOrderRepository::class, EloquentServiceOrderRepository::class);
        $this->app->bind(\App\Core\Interfaces\ITransferOrderRepository::class, \App\Infrastructure\Persistence\EloquentTransferOrderRepository::class);
        $this->app->bind(\App\Core\Interfaces\IWeaningOrderRepository::class, \App\Infrastructure\Persistence\EloquentWeaningOrderRepository::class);
        $this->app->bind(\App\Core\Interfaces\IEntryOrderRepository::class, \App\Infrastructure\Persistence\EloquentEntryOrderRepository::class);
        $this->app->bind(\App\Core\Interfaces\IBirthOrderRepository::class, \App\Infrastructure\Persistence\EloquentBirthOrderRepository::class);
        $this->app->bind(\App\Core\Interfaces\IAnimalCategoryRepository::class, \App\Infrastructure\Persistence\EloquentAnimalCategoryRepository::class);
        $this->app->bind(\App\Core\Interfaces\IPathogenRepository::class, \App\Infrastructure\Persistence\EloquentPathogenRepository::class);
        $this->app->bind(\App\Core\Interfaces\IVeterinaryDiagnosisRepository::class, \App\Infrastructure\Persistence\EloquentVeterinaryDiagnosisRepository::class);
        $this->app->bind(\App\Core\Interfaces\IBullHealthEvaluationRepository::class, \App\Infrastructure\Persistence\EloquentBullHealthEvaluationRepository::class);

        // Veterinary diagnostics module (hybrid sanitary architecture).
        $this->app->bind(\App\Core\Interfaces\IVeterinarianRepository::class, \App\Infrastructure\Persistence\EloquentVeterinarianRepository::class);
        $this->app->bind(\App\Core\Interfaces\IDiagnosticProtocolRepository::class, \App\Infrastructure\Persistence\EloquentDiagnosticProtocolRepository::class);
        $this->app->bind(\App\Core\Interfaces\ISampleShipmentRepository::class, \App\Infrastructure\Persistence\EloquentSampleShipmentRepository::class);
        $this->app->bind(\App\Core\Interfaces\IUserInvitationRepository::class, \App\Infrastructure\Persistence\EloquentUserInvitationRepository::class);
        $this->app->bind(\App\Core\Interfaces\IVeterinaryPortalAccessTokenRepository::class, \App\Infrastructure\Persistence\EloquentVeterinaryPortalAccessTokenRepository::class);
        $this->app->bind(\App\Core\Interfaces\IProtocolAttachmentStorage::class, \App\Infrastructure\Storage\LocalTenantAttachmentStorage::class);

        // ADR-12: extraction act numbers are minted by the system under a pessimistic lock.
        $this->app->bind(\App\Core\Interfaces\IProtocolNumberSequenceRepository::class, \App\Infrastructure\Persistence\EloquentProtocolNumberSequenceRepository::class);

        // One portal identity per request, populated by ResolveVeterinaryPortalAccess and read
        // by controllers and use cases through the Core interface.
        $this->app->scoped(\App\Infrastructure\Veterinary\VeterinaryPortalContext::class);
        $this->app->bind(
            \App\Core\Interfaces\IVeterinaryPortalContext::class,
            \App\Infrastructure\Veterinary\VeterinaryPortalContext::class
        );

        // ADR-4: the aptitude engine reads its thresholds from config/livestock.php, so the
        // sanitary rules stay auditable and adjustable without redeploying logic.
        $this->app->bind(
            \App\Core\Services\BullHealthEvaluationEngine::class,
            static fn (): \App\Core\Services\BullHealthEvaluationEngine => \App\Core\Services\BullHealthEvaluationEngine::fromConfig()
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
     }
}

