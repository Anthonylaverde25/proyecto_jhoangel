<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Interfaces\ICompanyItemCatalogRepository;
use App\Core\Interfaces\IConsumableBatchReader;
use App\Core\Interfaces\IFiscalCatalogRepository;
use App\Core\Interfaces\IFiscalDiscrepancyRepository;
use App\Core\Interfaces\IFiscalDocumentRepository;
use App\Core\Interfaces\IFiscalLineCatalogReader;
use App\Core\Interfaces\IItemCategoryRepository;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\Interfaces\IItemUsageReader;
use App\Core\Interfaces\IStockIncidentRepository;
use App\Core\Interfaces\IUnitOfMeasureRepository;
use App\Core\Interfaces\IUnvaluedLotRepository;
use App\Core\Interfaces\IWarehouseRepository;
use App\Infrastructure\Observers\CompanyItemCatalogObserver;
use App\Infrastructure\Persistence\EloquentCompanyItemCatalogRepository;
use App\Infrastructure\Persistence\EloquentConsumableBatchReader;
use App\Infrastructure\Persistence\EloquentFiscalCatalogRepository;
use App\Infrastructure\Persistence\EloquentFiscalDiscrepancyRepository;
use App\Infrastructure\Persistence\EloquentFiscalDocumentRepository;
use App\Infrastructure\Persistence\EloquentFiscalLineCatalogReader;
use App\Infrastructure\Persistence\EloquentItemCategoryRepository;
use App\Infrastructure\Persistence\EloquentItemDocumentRepository;
use App\Infrastructure\Persistence\EloquentItemRepository;
use App\Infrastructure\Persistence\EloquentItemStockRepository;
use App\Infrastructure\Persistence\EloquentItemUsageReader;
use App\Infrastructure\Persistence\EloquentStockIncidentRepository;
use App\Infrastructure\Persistence\EloquentUnitOfMeasureRepository;
use App\Infrastructure\Persistence\EloquentUnvaluedLotRepository;
use App\Infrastructure\Persistence\EloquentWarehouseRepository;
use App\Models\Company;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    /**
     * Bindings for Items, Catalog, Fiscal Documents, and Warehouse Stock.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ICompanyItemCatalogRepository::class => EloquentCompanyItemCatalogRepository::class,
        IConsumableBatchReader::class => EloquentConsumableBatchReader::class,
        IFiscalCatalogRepository::class => EloquentFiscalCatalogRepository::class,
        IFiscalDiscrepancyRepository::class => EloquentFiscalDiscrepancyRepository::class,
        IFiscalDocumentRepository::class => EloquentFiscalDocumentRepository::class,
        IFiscalLineCatalogReader::class => EloquentFiscalLineCatalogReader::class,
        IItemCategoryRepository::class => EloquentItemCategoryRepository::class,
        IItemDocumentRepository::class => EloquentItemDocumentRepository::class,
        IItemRepository::class => EloquentItemRepository::class,
        IItemStockRepository::class => EloquentItemStockRepository::class,
        IItemUsageReader::class => EloquentItemUsageReader::class,
        IStockIncidentRepository::class => EloquentStockIncidentRepository::class,
        IUnitOfMeasureRepository::class => EloquentUnitOfMeasureRepository::class,
        IUnvaluedLotRepository::class => EloquentUnvaluedLotRepository::class,
        IWarehouseRepository::class => EloquentWarehouseRepository::class,
    ];

    /**
     * Bootstrap item catalog and stock observers.
     */
    public function boot(): void
    {
        // Every company is born with its base units and categories (DB6).
        Company::observe(CompanyItemCatalogObserver::class);
    }
}
