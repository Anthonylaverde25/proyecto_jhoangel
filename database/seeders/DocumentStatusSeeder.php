<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $documentTypeId = DB::table('document_types')->insertGetId([
            'code' => 'SERVICE_ORDER',
            'name' => 'Orden de Servicio',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $statuses = [
            [
                'document_type_id' => $documentTypeId,
                'code' => 'DRAFT',
                'name' => 'Borrador',
                'color' => 'default',
                'is_initial' => true,
                'is_terminal' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'document_type_id' => $documentTypeId,
                'code' => 'APPROVED',
                'name' => 'Aprobada',
                'color' => 'info',
                'is_initial' => false,
                'is_terminal' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'document_type_id' => $documentTypeId,
                'code' => 'SUCCESS',
                'name' => 'Completada',
                'color' => 'success',
                'is_initial' => false,
                'is_terminal' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'document_type_id' => $documentTypeId,
                'code' => 'REJECTED',
                'name' => 'Rechazada',
                'color' => 'error',
                'is_initial' => false,
                'is_terminal' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'document_type_id' => $documentTypeId,
                'code' => 'CANCELLED',
                'name' => 'Cancelada',
                'color' => 'default',
                'is_initial' => false,
                'is_terminal' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($statuses as $status) {
            DB::table('document_statuses')->updateOrInsert(
                [
                    'document_type_id' => $status['document_type_id'],
                    'code' => $status['code']
                ],
                $status
            );
        }
    }
}
