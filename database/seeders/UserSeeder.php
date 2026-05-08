<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Crear Hacienda Principal
        $company1Id = DB::table('companies')->insertGetId([
            'name' => 'Hacienda Principal',
            'renspa' => '01.02.03.0001',
            'location' => 'Ruta Nacional 5',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Crear Hacienda Secundaria
        $company2Id = DB::table('companies')->insertGetId([
            'name' => 'Hacienda Secundaria',
            'renspa' => '09.08.07.0002',
            'location' => 'Ruta Provincial 10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Crear al usuario Administrador
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // 4. Vincular al usuario con ambas empresas
        DB::table('company_user')->insert([
            [
                'company_id' => $company1Id,
                'user_id' => $user->id,
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $company2Id,
                'user_id' => $user->id,
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}
