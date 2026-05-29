<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            DocumentStatusSeeder::class,
            GestationLossReasonSeeder::class,
            CaravanFieldMappingSeeder::class,
            BreedSeeder::class,
            LivestockHierarchySeeder::class,
            ActivitySeeder::class,
            BatchWeightSeeder::class,
            TemplateTypeSeeder::class,
            BatchTypeSeeder::class,
        ]);
    }
}
