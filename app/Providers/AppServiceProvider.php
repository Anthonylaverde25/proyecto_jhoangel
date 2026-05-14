<?php

namespace App\Providers;

use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IFieldMappingResolver;
use App\Core\Interfaces\IWorkdayRepository;
use App\Infrastructure\Persistence\EloquentCaravanRepository;
use App\Infrastructure\Persistence\EloquentFieldMappingResolver;
use App\Infrastructure\Persistence\EloquentWorkdayRepository;
use App\Core\Interfaces\IProviderRepository;
use App\Core\Interfaces\IFarmRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Infrastructure\Persistence\EloquentProviderRepository;
use App\Infrastructure\Persistence\EloquentFarmRepository;
use App\Infrastructure\Persistence\EloquentBatchRepository;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Infrastructure\Persistence\EloquentCaravanMovementRepository;
use App\Core\Interfaces\IActivityRepository;
use App\Infrastructure\Persistence\EloquentActivityRepository;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Infrastructure\Persistence\EloquentCaravanWeightRepository;
use App\Core\Interfaces\ITemplateTypeRepository;
use App\Infrastructure\Persistence\EloquentTemplateTypeRepository;
use App\Core\Interfaces\IWorkTemplateRepository;
use App\Infrastructure\Persistence\EloquentWorkTemplateRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Core\Interfaces\ICompanyContext::class, \App\Core\Contexts\CompanyContext::class);
        $this->app->bind(ICaravanRepository::class, EloquentCaravanRepository::class);
        $this->app->bind(ICaravanWeightRepository::class, EloquentCaravanWeightRepository::class);
        $this->app->bind(IFieldMappingResolver::class, EloquentFieldMappingResolver::class);
        $this->app->bind(IWorkdayRepository::class, EloquentWorkdayRepository::class);
        $this->app->bind(IProviderRepository::class, EloquentProviderRepository::class);
        $this->app->bind(IFarmRepository::class, EloquentFarmRepository::class);
        $this->app->bind(IBatchRepository::class, EloquentBatchRepository::class);
        $this->app->bind(\App\Core\Interfaces\ICompanyRepository::class, \App\Infrastructure\Persistence\EloquentCompanyRepository::class);
        $this->app->bind(\App\Core\Interfaces\IBreedRepository::class, \App\Infrastructure\Persistence\EloquentBreedRepository::class);
        $this->app->bind(ICaravanMovementRepository::class, EloquentCaravanMovementRepository::class);
        $this->app->bind(IActivityRepository::class, EloquentActivityRepository::class);
        $this->app->bind(ITemplateTypeRepository::class, EloquentTemplateTypeRepository::class);
        $this->app->bind(IWorkTemplateRepository::class, EloquentWorkTemplateRepository::class);
        $this->app->bind(\App\Core\Interfaces\IOCRProvider::class, function ($app) {
            $driver = config('services.ocr.driver');

            return match ($driver) {
                // 'google' => $app->make(\App\Infrastructure\OCR\GoogleOCRProvider::class),
                default  => $app->make(\App\Infrastructure\OCR\AzureOCRProvider::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
