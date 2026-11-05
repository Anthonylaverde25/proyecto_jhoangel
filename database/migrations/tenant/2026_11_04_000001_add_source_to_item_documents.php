<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_documents', function (Blueprint $table): void {
            // Who originated the operation. Null: not declared. 'AI': an AI channel, set by its route.
            $table->string('source', 24)->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('item_documents', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
