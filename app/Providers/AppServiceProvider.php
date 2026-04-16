<?php

namespace App\Providers;

use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IFieldMappingResolver;
use App\Core\Interfaces\IWorkdayRepository;
use App\Infrastructure\Persistence\EloquentCaravanRepository;
use App\Infrastructure\Persistence\EloquentFieldMappingResolver;
use App\Infrastructure\Persistence\EloquentWorkdayRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ICaravanRepository::class, EloquentCaravanRepository::class);
        $this->app->bind(IFieldMappingResolver::class, EloquentFieldMappingResolver::class);
        $this->app->bind(IWorkdayRepository::class, EloquentWorkdayRepository::class);

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
