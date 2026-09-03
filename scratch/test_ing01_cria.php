<?php

require '/Users/anthonylaverde/Desktop/proyecto_jhoangel/api-laravel/vendor/autoload.php';
$app = require_once '/Users/anthonylaverde/Desktop/proyecto_jhoangel/api-laravel/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Tenant;
use App\Models\Company;
use App\Models\Activity;
use App\Models\Batch;
use App\Models\Farm;
use App\Application\DTOs\Ing01\Ing01SubmissionDTO;
use App\Application\Services\Ing01TemplateProcessor;
use App\Application\Services\AnimalCategoryResolver;
use App\Application\Services\BreedAndColorResolver;
use App\Application\Services\ActivityResolver;

echo "===================================================\n";
echo "SIMULACIÓN DE INGRESO ING-01 (Actividad Cría)\n";
echo "===================================================\n\n";

// 1. Tomar el último tenant creado o crear uno de prueba
$tenants = Tenant::all();
if ($tenants->isEmpty()) {
    $tenant = Tenant::create(['id' => 'test-simulation-' . uniqid()]);
} else {
    $tenant = $tenants->last();
}

tenancy()->initialize($tenant);
echo "Tenancy initialized for: {$tenant->id}\n";

// Asegurar que existan actividades y empresa
$company = Company::firstOrCreate(
    ['name' => 'Hacienda Principal'],
    ['renspa' => '01.02.03.0001', 'is_active' => true]
);

$actCria = Activity::firstOrCreate(
    ['code' => 'CRIA'],
    ['name' => 'Cría', 'is_final' => false]
);
$actRecria = Activity::firstOrCreate(
    ['code' => 'RECRIA'],
    ['name' => 'Recría', 'is_final' => false]
);
$actInvernada = Activity::firstOrCreate(
    ['code' => 'INVERNADA'],
    ['name' => 'Invernada', 'is_final' => true]
);

echo "Empresa ID: {$company->id} ({$company->name})\n";
echo "Actividad Cría ID: {$actCria->id} (Code: {$actCria->code})\n\n";

// 2. Payload exacto extraído de ing01_03_actividad_cria.png
$payload = [
    'batch_name'          => 'RODEO CRIA POTRERO 4',
    'activity'            => 'Cría',
    'entry_date'          => '2026-08-28',
    'provider_name'       => 'Cabaña La Tranquera S.A.',
    'provider_cuit'       => '30-55443322-1',
    'provider_farm_name'  => 'La Tranquera - Lote 8',
    'provider_renspa'     => '03.012.3.45678/02',
    'provider_batch_name' => 'TROPA-CRIA-108',
    'guia_dte'            => 'DTE-2026-33981',
    'caravans' => [
        [
            'caravana' => 'CRIA-001',
            'category' => 'Vaca',
            'sex' => 'F',
            'breed' => 'Angus Negro',
            'teeth' => '6',
            'entry_weight' => '460',
            'observations' => 'Con cría al pie'
        ],
        [
            'caravana' => 'CRIA-002',
            'category' => 'Ternero',
            'sex' => 'M',
            'breed' => 'Angus Negro',
            'teeth' => '0',
            'entry_weight' => '120',
            'observations' => 'Cría de CRIA-001'
        ]
    ]
];

$dto = Ing01SubmissionDTO::fromArray($payload, $company->id);

echo "DTO creado:\n";
echo "  - DTO batchName: '{$dto->batchName}'\n";
echo "  - DTO activity : '{$dto->activity}'\n";
echo "  - DTO providerName: '{$dto->providerName}'\n\n";

// 3. Procesar con Ing01TemplateProcessor
$processor = app(Ing01TemplateProcessor::class);
$result = $processor->process($dto);

echo "Resultado del procesamiento:\n";
print_r($result['batch']);

// 4. Verificar en DB
$createdBatch = Batch::with(['activity', 'farm', 'caravans'])->find($result['batch']['id']);
echo "\nVerificación directa en Base de Datos:\n";
echo "  - Lote ID: {$createdBatch->id}\n";
echo "  - Lote Nombre: '{$createdBatch->name}'\n";
echo "  - Lote activity_id: " . ($createdBatch->activity_id ?? 'NULL') . "\n";
echo "  - Lote Actividad Relación: " . ($createdBatch->activity ? $createdBatch->activity->name . " (Code: " . $createdBatch->activity->code . ")" : 'NULL') . "\n";
echo "  - Lote Granja: " . ($createdBatch->farm ? $createdBatch->farm->name : 'NULL') . "\n";
echo "  - Lote Cantidad Animales: " . $createdBatch->caravans->count() . "\n";

tenancy()->end();
