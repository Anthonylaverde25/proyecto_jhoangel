<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Interfaces\ISemenInventoryReader;
use App\Core\Interfaces\ISemenLotRepository;
use App\Core\Interfaces\ISemenProfileRepository;
use App\Core\Interfaces\ISemenSireRepository;
use App\Core\Interfaces\ISemenUnidentifiedRepository;
use App\Core\Interfaces\IServiceOrderInseminationRepository;
use App\Infrastructure\Persistence\EloquentSemenInventoryReader;
use App\Infrastructure\Persistence\EloquentSemenLotRepository;
use App\Infrastructure\Persistence\EloquentSemenProfileRepository;
use App\Infrastructure\Persistence\EloquentSemenSireRepository;
use App\Infrastructure\Persistence\EloquentSemenUnidentifiedRepository;
use App\Infrastructure\Persistence\EloquentServiceOrderInseminationRepository;
use Illuminate\Support\ServiceProvider;

final class GeneticsServiceProvider extends ServiceProvider
{
    /**
     * Bindings for Genetic Material, Semen Lots, Sires, and Insemination.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ISemenInventoryReader::class => EloquentSemenInventoryReader::class,
        ISemenLotRepository::class => EloquentSemenLotRepository::class,
        ISemenProfileRepository::class => EloquentSemenProfileRepository::class,
        ISemenSireRepository::class => EloquentSemenSireRepository::class,
        ISemenUnidentifiedRepository::class => EloquentSemenUnidentifiedRepository::class,
        IServiceOrderInseminationRepository::class => EloquentServiceOrderInseminationRepository::class,
    ];
}
