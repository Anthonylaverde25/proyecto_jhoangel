<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Tenant;
use App\Models\ServiceOrder;
use App\Models\CaravanGestation;
use App\Application\DTOs\ImportOCRGestationDiagnosisDTO;
use App\Application\UseCases\Caravans\ImportGestationDiagnosisFromOCRUseCase;

$tenant = Tenant::find('dev_tenant');
tenancy()->initialize($tenant);

$companyId = \DB::table('companies')->first()->id;

$serviceOrder = ServiceOrder::where('code', 'SO-20260605-105950-7238')->first();
if (!$serviceOrder) {
    echo "Service Order not found!\n";
    exit(1);
}

// Reset gestations for the 5 caravans to start clean
$caravanIds = \DB::table('caravans')
    ->whereIn('identification', ['CAR-2-1-274', 'CAR-2-2-413', 'CAR-2-3-240', 'CAR-2-4-388', 'CAR-2-5-569'])
    ->pluck('id')
    ->toArray();

CaravanGestation::whereIn('caravan_id', $caravanIds)->delete();

echo "Initial gestation count: " . CaravanGestation::whereIn('caravan_id', $caravanIds)->count() . "\n";

$useCase = app(ImportGestationDiagnosisFromOCRUseCase::class);

$dto = new ImportOCRGestationDiagnosisDTO(
    rows: [
        [
            'identification' => 'CAR-2-1-274',
            'diagnostico' => 'PREGNANT',
            'gestation_stage' => 'head',
            'observations' => 'Gestación normal, buen desarrollo.'
        ],
        [
            'identification' => 'CAR-2-2-413',
            'diagnostico' => 'EMPTY',
            'gestation_stage' => null,
            'observations' => 'Revisar nutrición para próximo ciclo.'
        ],
        [
            'identification' => 'CAR-2-3-240',
            'diagnostico' => 'PREGNANT',
            'gestation_stage' => 'body',
            'observations' => 'Preñez confirmada, etapa media.'
        ],
        [
            'identification' => 'CAR-2-4-388',
            'diagnostico' => 'EMPTY',
            'gestation_stage' => null,
            'observations' => 'Sin anomalías detectadas.'
        ],
        [
            'identification' => 'CAR-2-5-569',
            'diagnostico' => 'PREGNANT',
            'gestation_stage' => 'tail',
            'observations' => 'Preñez avanzada, próxima a separar.'
        ]
    ],
    serviceOrderId: $serviceOrder->id,
    diagnosisDate: '2026-06-05'
);

$result = $useCase($dto, $companyId);

echo "Import Result:\n";
print_r($result);

$gestations = CaravanGestation::whereIn('caravan_id', $caravanIds)->with('caravan')->get();
echo "Final gestation count: " . $gestations->count() . "\n";
foreach ($gestations as $g) {
    echo "Caravan: {$g->caravan?->identification} | Current: " . ($g->is_current ? 'YES' : 'NO') . " | Stage: " . ($g->gestation_stage?->value ?? 'NULL') . "\n";
}
