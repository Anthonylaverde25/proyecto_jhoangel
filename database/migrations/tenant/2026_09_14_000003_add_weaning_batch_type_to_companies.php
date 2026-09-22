<?php

declare(strict_types=1);

use App\Models\BatchType;
use App\Models\Company;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $companies = Company::all();

        foreach ($companies as $company) {
            BatchType::withoutGlobalScopes()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => 'WEANING',
                ],
                [
                    'name' => 'Lote de Destete',
                    'description' => 'Lote transitorio de desmadre, acostumbramiento o concentración para comercialización',
                    'icon' => 'heroicons-outline:clock',
                    'color' => '#8b5cf6',
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        BatchType::withoutGlobalScopes()->where('code', 'WEANING')->delete();
    }
};
