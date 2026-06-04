<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_templates', function (Blueprint $table) {
            $table->string('code')->nullable()->after('status');
            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_templates', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
