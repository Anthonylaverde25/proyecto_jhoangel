<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// I7: compares every balance with its movements. Reports, never corrects: correcting is a count.
Artisan::command('stock:reconcile', function (\App\Core\Interfaces\IItemStockRepository $stocks) {
    $differences = $stocks->reconcile();
    if ($differences === []) {
        $this->info('Sin diferencias: cada saldo coincide con sus movimientos.');

        return 0;
    }
    foreach ($differences as $row) {
        $this->error("Empresa {$row['company_id']} · almacén {$row['warehouse_id']} · ítem {$row['item_id']}: " . implode('; ', $row['problems']));
    }

    return 1;
})->purpose('Compara cada saldo de stock con su libro de movimientos (I7)');
