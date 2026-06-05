<?php

// Bootstrapping Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Caravan;
use App\Models\ServiceOrder;
use App\Models\CaravanGestation;
use App\Application\DTOs\ImportOCRGestationDiagnosisDTO;
use App\Application\UseCases\Caravans\ImportGestationDiagnosisFromOCRUseCase;

// Set Tenant Context
$tenant = \App\Models\Tenant::find('dev_tenant');
tenancy()->initialize($tenant);

$companyId = \DB::table('companies')->first()->id;

// Resolve Service Order
$serviceOrder = ServiceOrder::where('code', 'SO-20260605-105950-7238')->first();
if (!$serviceOrder) {
    echo "Service Order not found!\n";
    exit(1);
}

// Find a caravan (specifically one of our seed vacas, e.g. CAR-2-2-413)
$caravan = Caravan::where('identification', 'CAR-2-2-413')->first();
if (!$caravan) {
    echo "Caravan not found!\n";
    exit(1);
}

// Clean gestations for this caravan first to start fresh
CaravanGestation::where('caravan_id', $caravan->id)->delete();

echo "Initial gestation count for caravan {$caravan->identification}: " . CaravanGestation::where('caravan_id', $caravan->id)->count() . "\n";

// Execute Use Case
$useCase = app(ImportGestationDiagnosisFromOCRUseCase::class);

$dto = new ImportOCRGestationDiagnosisDTO(
    rows: [
        [
            'identification' => 'CAR-2-2-413',
            'diagnostico' => 'EMPTY', // Vacia
            'gestation_stage' => null,
            'observations' => 'Test vacia'
        ]
    ],
    serviceOrderId: $serviceOrder->id,
    diagnosisDate: '2026-06-05'
);

$result = $useCase($dto, $companyId);

echo "Import Result:\n";
print_r($result);

echo "Final gestation count for caravan {$caravan->identification}: " . CaravanGestation::where('caravan_id', $caravan->id)->count() . "\n";
if (CaravanGestation::where('caravan_id', $caravan->id)->count() > 0) {
    echo "Gestation record created:\n";
    print_r(CaravanGestation::where('caravan_id', $caravan->id)->get()->toArray());
} else {
    echo "No gestation record created. Correct!\n";
}
