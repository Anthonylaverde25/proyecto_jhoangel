<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_lines', function (Blueprint $table) {
            // What the provider's paper said for this line, when it was read from an image (Delivery G).
            $table->string('source_description', 191)->nullable()->after('item_id');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_lines', function (Blueprint $table) {
            $table->dropColumn('source_description');
        });
    }
};
