<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\CaravanGestation;

$tenant = \App\Models\Tenant::find('dev_tenant');
tenancy()->initialize($tenant);

$gestations = CaravanGestation::with('caravan')->get();

echo "TOTAL GESTATIONS: " . $gestations->count() . "\n\n";

foreach ($gestations as $g) {
    echo "ID: {$g->id} | Caravan: {$g->caravan?->identification} | Current: " . ($g->is_current ? 'YES' : 'NO') . " | Stage: " . ($g->gestation_stage?->value ?? 'NULL') . " | Success: " . ($g->success === null ? 'NULL' : ($g->success ? 'YES' : 'NO')) . " | Order ID: {$g->service_order_id} | Date: " . ($g->start_date ? $g->start_date->format('Y-m-d') : 'NULL') . "\n";
}
