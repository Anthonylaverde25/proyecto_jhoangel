<?php

namespace App\Providers;

use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IFieldMappingResolver;
use App\Infrastructure\Persistence\EloquentCaravanRepository;
use App\Infrastructure\Persistence\EloquentFieldMappingResolver;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
