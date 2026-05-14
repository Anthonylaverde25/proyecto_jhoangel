<?php

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
        Schema::create('work_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('type_id')->constrained('template_types')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('schema_definition')->nullable(); // Para guardar los campos dinámicos
            $table->string('status')->default('active'); // active, draft, archived
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_templates');
    }
};
