<?php

require '/Users/anthonylaverde/Desktop/proyecto_jhoangel/api-laravel/vendor/autoload.php';
$app = require_once '/Users/anthonylaverde/Desktop/proyecto_jhoangel/api-laravel/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Tenant;
use App\Models\Company;
use App\Models\Activity;
use App\Models\Batch;
use App\Application\DTOs\Ing01\Ing01SubmissionDTO;
use App\Application\Services\Ing01TemplateProcessor;

echo "=================================================================================\n";
echo "PRUEBA END-TO-END: PERSISTENCIA DE ING-01 CON ACTIVIDAD 'CRÍA' EN TENANT REAL\n";
echo "=================================================================================\n\n";

$tenant = Tenant::firstOrCreate(['id' => 'dev_tenant']);
tenancy()->initialize($tenant);

$company = Company::first();
if (!$company) {
    $company = Company::create(['name' => 'Hacienda Principal', 'renspa' => '01.02.03.0001', 'is_active' => true]);
}

echo "Tenant Activo: {$tenant->id}\n";
echo "Empresa Activa: ID {$company->id} ({$company->name})\n\n";

// Simular el payload extraído por AI Agent desde ing01_03_actividad_cria.png
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
            'caravana'     => 'TEST-CRIA-01',
            'category'     => 'Vaca',
            'sex'          => 'F',
            'breed'        => 'Angus Negro',
            'teeth'        => '6',
            'entry_weight' => '475.5',
            'observations' => 'Hembra de cría'
        ],
        [
            'caravana'     => 'TEST-CRIA-02',
            'category'     => 'Ternero',
            'sex'          => 'M',
            'breed'        => 'Angus Negro',
            'teeth'        => '0',
            'entry_weight' => '130.0',
            'observations' => 'Ternero al pie'
        ]
    ]
];

$dto = Ing01SubmissionDTO::fromArray($payload, $company->id);

$processor = app(Ing01TemplateProcessor::class);
$result = $processor->process($dto);

$batchId = $result['batch']['id'];
$batch = Batch::with(['activity', 'farm', 'caravans', 'batchType'])->find($batchId);

echo "RESULTADO DE LA PERSISTENCIA:\n";
echo "  • Lote ID: {$batch->id}\n";
echo "  • Nombre del Lote: '{$batch->name}'\n";
echo "  • Alcance: " . (is_null($batch->farm?->provider_id) ? "PROPIO" : "EXTERNO") . "\n";
echo "  • Activity ID: {$batch->activity_id}\n";
echo "  • Nombre de la Actividad: " . ($batch->activity?->name ?? 'NULL') . "\n";
echo "  • Código de la Actividad: " . ($batch->activity?->code ?? 'NULL') . "\n";
echo "  • Campo Asignado: " . ($batch->farm?->name ?? 'NULL') . "\n";
echo "  • Total de Caravanas Asignadas: " . $batch->caravans->count() . "\n\n";

if ($batch->activity_id !== null && $batch->activity?->code === 'CRIA') {
    echo "🎉 ¡ÉXITO TOTAL! El Lote Propio fue creado y asociado correctamente con la actividad Cría (ID: {$batch->activity_id}).\n";
} else {
    echo "❌ ERROR: No se asoció la actividad esperada.\n";
}

tenancy()->end();
