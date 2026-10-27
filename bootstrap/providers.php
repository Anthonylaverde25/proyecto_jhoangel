<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AssistantServiceProvider;
use App\Providers\GeneticsServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\LivestockServiceProvider;
use App\Providers\TenancyServiceProvider;
use App\Providers\VeterinaryServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    LivestockServiceProvider::class,
    VeterinaryServiceProvider::class,
    InventoryServiceProvider::class,
    GeneticsServiceProvider::class,
    AssistantServiceProvider::class,
];
