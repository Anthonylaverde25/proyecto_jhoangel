<?php

$databaseDir = '/Users/anthonylaverde/Desktop/proyecto_jhoangel/api-laravel/database';
$files = glob($databaseDir . '/tenant_*');

usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

echo "=================================================================================\n";
echo "REPORTE ACTUALIZADO: LOTES CON ACTIVIDADES ASIGNADAS EN LA BASE DE DATOS\n";
echo "=================================================================================\n\n";

$totalBatchesWithActivity = 0;
$totalOwnBatchesWithActivity = 0;
$totalExtBatchesWithActivity = 0;

foreach ($files as $dbFile) {
    if (filesize($dbFile) < 10000) continue;
    $filename = basename($dbFile);
    $mtime = date('Y-m-d H:i:s', filemtime($dbFile));
    
    try {
        $pdo = new PDO("sqlite:" . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table';");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('batches', $tables)) continue;
        
        $stmtB = $pdo->query("SELECT * FROM batches");
        $batches = $stmtB->fetchAll(PDO::FETCH_ASSOC);
        if (count($batches) === 0) continue;
        
        // Mapas auxiliares
        $companies = [];
        if (in_array('companies', $tables)) {
            foreach ($pdo->query("SELECT * FROM companies")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $companies[$c['id']] = $c;
            }
        }
        
        $activities = [];
        if (in_array('activities', $tables)) {
            foreach ($pdo->query("SELECT * FROM activities")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $activities[$a['id']] = $a;
            }
        }

        $farms = [];
        if (in_array('farms', $tables)) {
            foreach ($pdo->query("SELECT * FROM farms")->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $farms[$f['id']] = $f;
            }
        }

        $batchTypes = [];
        if (in_array('batch_types', $tables)) {
            foreach ($pdo->query("SELECT * FROM batch_types")->fetchAll(PDO::FETCH_ASSOC) as $bt) {
                $batchTypes[$bt['id']] = $bt['name'] ?? $bt['code'];
            }
        }

        // Filtrar lotes que SI tienen actividad
        $batchesWithActivity = [];
        foreach ($batches as $b) {
            if (!empty($b['activity_id'])) {
                $batchesWithActivity[] = $b;
            }
        }

        if (count($batchesWithActivity) > 0) {
            echo "=================================================================================\n";
            echo "DATABASE: {$filename} (Modificada: {$mtime})\n";
            echo "Total Lotes en DB: " . count($batches) . " | Lotes con Actividad Asignada: " . count($batchesWithActivity) . "\n";
            echo "=================================================================================\n";

            foreach ($batchesWithActivity as $b) {
                $farmId = $b['farm_id'] ?? null;
                $farm = ($farmId && isset($farms[$farmId])) ? $farms[$farmId] : null;
                $isOwn = is_null($farmId) || (is_null($farm['provider_id'] ?? null));
                $scopeStr = $isOwn ? "PROPIO" : "EXTERNO";

                $totalBatchesWithActivity++;
                if ($isOwn) {
                    $totalOwnBatchesWithActivity++;
                } else {
                    $totalExtBatchesWithActivity++;
                }

                $companyId = $b['company_id'] ?? null;
                $company = ($companyId && isset($companies[$companyId])) ? $companies[$companyId] : null;
                $companyName = $company ? $company['name'] : ("Empresa ID " . ($companyId ?? 'N/A'));

                $actId = $b['activity_id'];
                $act = isset($activities[$actId]) ? $activities[$actId] : null;
                $actName = $act ? "{$act['name']} (Código: {$act['code']})" : "Actividad ID {$actId}";

                $farmName = $farm ? $farm['name'] : 'Sin Campo / Campo Propio';
                $typeName = isset($b['batch_type_id']) && isset($batchTypes[$b['batch_type_id']]) ? $batchTypes[$b['batch_type_id']] : 'General';

                $animalCount = 0;
                if (in_array('caravans', $tables)) {
                    $stmtCar = $pdo->prepare("SELECT COUNT(*) FROM caravans WHERE batch_id = ?");
                    $stmtCar->execute([$b['id']]);
                    $animalCount = (int)$stmtCar->fetchColumn();
                }

                echo "📌 LOTE [{$scopeStr}] (ID: {$b['id']})\n";
                echo "   • Nombre del Lote  : '{$b['name']}'\n";
                echo "   • Alcance          : {$scopeStr}\n";
                echo "   • Actividad        : {$actName}\n";
                echo "   • Empresa Asignada : {$companyName}\n";
                echo "   • Campo / Granja   : {$farmName}\n";
                echo "   • Tipo de Lote     : {$typeName}\n";
                echo "   • Cant. Animales   : {$animalCount}\n";
                echo "   • Es de Sistema    : " . (!empty($b['is_system']) ? 'SÍ' : 'NO') . "\n";
                echo "   • Estado Activo    : " . (!empty($b['is_active']) ? 'SÍ' : 'NO') . "\n";
                echo "---------------------------------------------------------------------------------\n";
            }
            echo "\n";
        }

    } catch (\Throwable $e) {
        // Ignorar errores menores
    }
}

echo "=================================================================================\n";
echo "RESUMEN GLOBAL:\n";
echo "  • Total Lotes con Actividad Asignada: {$totalBatchesWithActivity}\n";
echo "  • De los cuales Lotes PROPIOS con Actividad: {$totalOwnBatchesWithActivity}\n";
echo "  • De los cuales Lotes EXTERNOS con Actividad: {$totalExtBatchesWithActivity}\n";
echo "=================================================================================\n";
