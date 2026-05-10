<?php

namespace Database\Seeders;

use App\Models\User;
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
            CaravanFieldMappingSeeder::class,
            BreedSeeder::class,
            LivestockHierarchySeeder::class,
            ActivitySeeder::class,
            BatchWeightSeeder::class,
        ]);
    }
}
