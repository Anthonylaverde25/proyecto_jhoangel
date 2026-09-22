<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create company_batch_type pivot table if not exists
        if (!Schema::hasTable('company_batch_type')) {
            Schema::create('company_batch_type', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('batch_type_id')->constrained('batch_types')->cascadeOnDelete();
                $table->boolean('is_enabled')->default(true);
                $table->string('custom_name', 100)->nullable();
                $table->string('custom_color', 50)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'batch_type_id'], 'uq_company_batch_type');
                $table->index(['company_id', 'is_enabled']);
            });
        }

        // 2. Safe Data Migration: Identify canonical batch_types and populate pivot
        $uniqueCodes = DB::table('batch_types')->select('code')->distinct()->pluck('code');

        $canonicalMap = []; // code => canonical_id
        $obsoleteMap = [];  // obsolete_id => canonical_id

        foreach ($uniqueCodes as $code) {
            $records = DB::table('batch_types')
                ->where('code', $code)
                ->orderBy('id', 'asc')
                ->get();

            $canonical = $records->first();
            $canonicalMap[$code] = $canonical->id;

            foreach ($records as $record) {
                if ($record->id !== $canonical->id) {
                    $obsoleteMap[$record->id] = $canonical->id;
                }

                if (isset($record->company_id) && !empty($record->company_id)) {
                    DB::table('company_batch_type')->insertOrIgnore([
                        'company_id'    => $record->company_id,
                        'batch_type_id' => $canonical->id,
                        'is_enabled'    => (bool) $record->is_active,
                        'custom_name'   => null,
                        'custom_color'  => null,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }
        }

        // 3. Remap batches pointing to obsolete batch_type_id
        foreach ($obsoleteMap as $obsoleteId => $canonicalId) {
            DB::table('batches')
                ->where('batch_type_id', $obsoleteId)
                ->update(['batch_type_id' => $canonicalId]);
        }

        // 4. Delete obsolete duplicate records from batch_types
        if (!empty($obsoleteMap)) {
            DB::table('batch_types')
                ->whereIn('id', array_keys($obsoleteMap))
                ->delete();
        }

        // 5. Update batch_types table structure
        if (DB::getDriverName() === 'sqlite') {
            Schema::disableForeignKeyConstraints();

            Schema::create('batch_types_temp', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('code', 50)->unique('uq_batch_types_code');
                $table->text('description')->nullable();
                $table->string('color')->nullable();
                $table->string('icon', 100)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            DB::statement('INSERT INTO batch_types_temp (id, name, code, description, color, icon, is_active, created_at, updated_at) SELECT id, name, code, description, color, icon, is_active, created_at, updated_at FROM batch_types');

            Schema::drop('batch_types');
            Schema::rename('batch_types_temp', 'batch_types');

            Schema::enableForeignKeyConstraints();
        } else {
            Schema::disableForeignKeyConstraints();

            $foreignKeys = collect(Schema::getForeignKeys('batch_types'))->pluck('name')->all();
            if (in_array('batch_types_company_id_foreign', $foreignKeys)) {
                Schema::table('batch_types', function (Blueprint $table) {
                    $table->dropForeign(['company_id']);
                });
            }

            $indexes = collect(Schema::getIndexes('batch_types'))->pluck('name')->all();

            Schema::table('batch_types', function (Blueprint $table) use ($indexes) {
                if (in_array('uq_company_batch_type_code', $indexes)) {
                    $table->dropUnique('uq_company_batch_type_code');
                }

                if (in_array('batch_types_company_id_is_active_index', $indexes)) {
                    $table->dropIndex('batch_types_company_id_is_active_index');
                }

                if (Schema::hasColumn('batch_types', 'company_id')) {
                    $table->dropColumn('company_id');
                }

                if (!in_array('uq_batch_types_code', $indexes)) {
                    $table->unique('code', 'uq_batch_types_code');
                }
            });

            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        // 1. Re-add company_id to batch_types
        Schema::table('batch_types', function (Blueprint $table) {
            $table->dropUnique('uq_batch_types_code');
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->cascadeOnDelete();
            $table->unique(['company_id', 'code'], 'uq_company_batch_type_code');
            $table->index(['company_id', 'is_active']);
        });

        // 2. Drop pivot table
        Schema::dropIfExists('company_batch_type');

        Schema::enableForeignKeyConstraints();
    }
};
