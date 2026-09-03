<?php

require '/Users/anthonylaverde/Desktop/proyecto_jhoangel/api-laravel/vendor/autoload.php';
$app = require_once '/Users/anthonylaverde/Desktop/proyecto_jhoangel/api-laravel/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Tenant;
use Database\Seeders\WorkTemplateSeeder;

echo "===================================================\n";
echo "SINCRONIZANDO SCHEMA_DEFINITION DE PLANTILLAS EN TODOS LOS TENANTS\n";
echo "===================================================\n\n";

$tenants = Tenant::all();
echo "Total de tenants registrados en central: " . $tenants->count() . "\n";

foreach ($tenants as $tenant) {
    try {
        tenancy()->initialize($tenant);
        echo " -> Sincronizando Tenant: {$tenant->id}... ";
        
        $seeder = new WorkTemplateSeeder();
        $seeder->run();
        
        echo "OK\n";
        tenancy()->end();
    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        try { tenancy()->end(); } catch (\Throwable $t) {}
    }
}

// También sincronizar cualquier archivo SQLite tenant_* directamente si no estaba en la tabla central
$databaseDir = '/Users/anthonylaverde/Desktop/proyecto_jhoangel/api-laravel/database';
$files = glob($databaseDir . '/tenant_*');

$seeder = new WorkTemplateSeeder();
// Obtener el schema_definition actualizado de ING-01
$schemaIng01 = null;

// Ejecutar un seeder dummy para extraer el schema_definition
$refClass = new ReflectionClass(WorkTemplateSeeder::class);

echo "\nVerificando y sincronizando bases de datos SQLite individuales...\n";
foreach ($files as $dbFile) {
    if (filesize($dbFile) < 10000) continue;
    $filename = basename($dbFile);
    
    try {
        $pdo = new PDO("sqlite:" . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table';");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('work_templates', $tables) || !in_array('companies', $tables)) continue;
        
        $companies = $pdo->query("SELECT id FROM companies")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($companies)) continue;

        $targetSchema = [
            'header_fields' => [
                [
                    'name' => 'lote',
                    'label' => 'Nombre del Lote Propio',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => "Nombre del lote interno propio donde se ingresarán los animales. Ej: LOTE 104, RECRIA 2. Puede estar en blanco si se usa lote del proveedor.",
                ],
                [
                    'name' => 'activity',
                    'label' => 'Actividad del Lote (Destino)',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => "Actividad productiva del lote propio de destino (ej: 'CRIA', 'RECRIA', 'INVERNADA', 'Cría', 'Recría', 'Invernada'). Extraer el nombre o código tal como figure en la cabecera.",
                ],
                [
                    'name' => 'provider_name',
                    'label' => 'Nombre del Proveedor / Vendedor',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => 'Nombre o razón social del vendedor/proveedor de la tropa.',
                ],
                [
                    'name' => 'provider_cuit',
                    'label' => 'CUIT del Proveedor / Vendedor',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => 'Formato de 11 dígitos con o sin guiones. Ej: 30-12345678-9',
                ],
                [
                    'name' => 'provider_farm_name',
                    'label' => 'Establecimiento de Origen (Campo Vendedor)',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => 'Nombre del campo, finca o establecimiento de origen del proveedor. Ej: Establecimiento Norte, La Porteña',
                ],
                [
                    'name' => 'provider_renspa',
                    'label' => 'RENSPA de Origen',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => 'RENSPA del establecimiento de origen del proveedor. Formato XX.XXX.X.XXXXX/XX',
                ],
                [
                    'name' => 'provider_batch_name',
                    'label' => 'Lote de Origen (Proveedor)',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => 'Nombre o código de lote/tropa externa de origen asignada por el proveedor. Ej: TROPA-492',
                ],
                [
                    'name' => 'guia_dte',
                    'label' => 'N° de DTE / Guía de Traslado',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => 'Número de documento de tránsito electrónico o remito',
                ],
                [
                    'name' => 'entry_date',
                    'label' => 'Fecha de Ingreso',
                    'type' => 'date',
                    'required' => true,
                    'default' => 'today',
                    'ai_hint' => 'Fecha en el encabezado (DD/MM/AAAA o AAAA-MM-DD)',
                ],
            ],
            'table_columns' => [
                [
                    'name' => 'caravana',
                    'label' => 'Caravana / Tag',
                    'type' => 'string',
                    'required' => true,
                    'validation' => [
                        'rules' => ['required', 'string', 'max:30'],
                    ],
                    'ai_hint' => 'Número visible o código de caravana/botón. Ej: 1024, 058, AR-492',
                ],
                [
                    'name' => 'category',
                    'label' => 'Categoría / Subcategoría',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => "Categoría zootécnica (ej: 'Vaquillona', 'Vaca', 'Toro', 'Ternero/a', 'Novillito', 'Descarte').",
                ],
                [
                    'name' => 'sex',
                    'label' => 'Sexo',
                    'type' => 'select',
                    'required' => false,
                    'options' => ['M', 'H', 'F'],
                    'ai_hint' => "Sexo del animal: 'M' (Macho) o 'H'/'F' (Hembra). Inferir por la categoría si está en blanco.",
                ],
                [
                    'name' => 'breed',
                    'label' => 'Raza / Color',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => "Raza o cruza fenotípica (ej: 'Angus', 'Hereford', 'Brangus', 'Braford', 'Cruza') y/o color si se especifica.",
                ],
                [
                    'name' => 'teeth',
                    'label' => 'Dentición / Edad',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => "Dentición o cronometría dentaria (ej: 'DL' / Diente de Leche / 0, '2D', '4D', '6D', '8D' / Boca Llena / Medio Diente).",
                ],
                [
                    'name' => 'entry_weight',
                    'label' => 'Peso de Ingreso (kg)',
                    'type' => 'number',
                    'required' => false,
                    'validation' => [
                        'rules' => ['nullable', 'numeric', 'min:0', 'max:2000'],
                    ],
                    'ai_hint' => 'Peso en kilogramos registrado en báscula. Ej: 420.5',
                ],
                [
                    'name' => 'observations',
                    'label' => 'Observaciones / Sanidad',
                    'type' => 'string',
                    'required' => false,
                    'ai_hint' => 'Anotaciones sanitarias, marcas a fuego, o condición particular del animal.',
                ],
            ]
        ];

        $jsonSchema = json_encode($targetSchema, JSON_UNESCAPED_UNICODE);

        foreach ($companies as $compVal) {
            $stmtCheck = $pdo->prepare("SELECT id FROM work_templates WHERE code='ING-01' AND company_id=?");
            $stmtCheck->execute([$compVal]);
            $existingId = $stmtCheck->fetchColumn();
            
            if ($existingId) {
                $stmtUp = $pdo->prepare("UPDATE work_templates SET schema_definition=? WHERE id=?");
                $stmtUp->execute([$jsonSchema, $existingId]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO work_templates (company_id, code, category, title, description, status, schema_definition, created_at, updated_at) VALUES (?, 'ING-01', 'ENTRY', 'Ingreso de Compra Directa', 'Registro básico de ingreso con datos de proveedor y pesaje inicial.', 'active', ?, datetime('now'), datetime('now'))");
                $stmtIns->execute([$compVal, $jsonSchema]);
            }
        }
        
        echo " -> {$filename}: OK (Schema ING-01 actualizado)\n";
        
    } catch (\Throwable $e) {
        echo " -> {$filename}: ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n¡Sincronización completada exitosamente!\n";
