<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the old check constraint (MySQL safe)
        $this->dropCheckConstraint('caravans', 'check_teeth_range');

        // 1. Alter existing columns
        Schema::table('caravans', function (Blueprint $table) {
            $table->string('identification')->change();
            $table->string('category')->nullable()->change();
        });

        // 2. Add new columns only if they don't exist (idempotent)
        Schema::table('caravans', function (Blueprint $table) {
            if (!Schema::hasColumn('caravans', 'breed')) {
                $table->string('breed')->nullable()->after('category');
            }
            if (!Schema::hasColumn('caravans', 'sex')) {
                $table->string('sex')->after('breed');
            }
            if (!Schema::hasColumn('caravans', 'entry_date')) {
                $table->date('entry_date')->nullable()->after('exit_weight');
            }
        });

        // Re-apply teeth constraint
        DB::statement('ALTER TABLE caravans ADD CONSTRAINT check_teeth_range CHECK (teeth >= 0 AND teeth <= 99)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropCheckConstraint('caravans', 'check_teeth_range');

        Schema::table('caravans', function (Blueprint $table) {
            $table->dropColumn(['breed', 'sex', 'entry_date']);
            $table->unsignedInteger('identification')->change();
            $table->enum('category', [
                'novillito', 'novillo', 'vaquillona', 'vaca', 'vaca_vacia', 'ternero', 'toro'
            ])->change();
        });

        DB::statement('ALTER TABLE caravans ADD CONSTRAINT check_teeth_range CHECK (teeth >= 0 AND teeth <= 99)');
    }

    /**
     * Safely drop a CHECK constraint in MySQL.
     */
    private function dropCheckConstraint(string $table, string $constraint): void
    {
        $exists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $constraint]
        );

        if (!empty($exists)) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$constraint}");
        }
    }
};
