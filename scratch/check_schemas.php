<?php

require '/Users/anthonylaverde/Desktop/proyecto_jhoangel/api-laravel/vendor/autoload.php';

$databaseDir = '/Users/anthonylaverde/Desktop/proyecto_jhoangel/api-laravel/database';
$files = glob($databaseDir . '/tenant_*');

echo "===================================================\n";
echo "REVISIÓN DE SCHEMA_DEFINITION DE ING-01 EN TENANTS\n";
echo "===================================================\n\n";

foreach ($files as $dbFile) {
    if (filesize($dbFile) < 10000) continue;
    $filename = basename($dbFile);
    
    try {
        $pdo = new PDO("sqlite:" . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table';");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('work_templates', $tables)) continue;
        
        $stmtT = $pdo->query("SELECT id, code, title, schema_definition FROM work_templates WHERE code='ING-01'");
        $template = $stmtT->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            echo "DATABASE: {$filename} -> No tiene ING-01\n";
            continue;
        }
        
        $schema = json_decode($template['schema_definition'] ?? '{}', true);
        $headerFields = $schema['header_fields'] ?? [];
        $headerNames = array_map(fn($f) => $f['name'] ?? 'sin_nombre', $headerFields);
        
        $hasActivity = in_array('activity', $headerNames) || in_array('actividad', $headerNames);
        
        if (!$hasActivity) {
            echo "⚠️ DATABASE: {$filename} -> ING-01 NO TIENE CAMPO DE ACTIVIDAD en header_fields! Fields: " . implode(', ', $headerNames) . "\n";
        } else {
            echo "✅ DATABASE: {$filename} -> ING-01 tiene campo actividad. Fields: " . implode(', ', $headerNames) . "\n";
        }
        
    } catch (\Throwable $e) {
        echo "Error in {$filename}: " . $e->getMessage() . "\n";
    }
}
