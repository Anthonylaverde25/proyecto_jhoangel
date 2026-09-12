<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Graphical and documentary evidence: WhatsApp photos, scanned chute notebooks,
 * letterheaded laboratory PDFs. Files live in the private `tenant` disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_attachments', function (Blueprint $table) {
            $table->id();
            // F12: keeps the tenancy pattern used by every other domain table.
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('diagnostic_protocol_id')->constrained('diagnostic_protocols')->onDelete('cascade');
            $table->string('file_path', 500)->comment('Relative path inside the private tenant disk');
            $table->string('file_name', 255)->comment('Original filename, e.g. informe_lab.jpg');
            $table->string('mime_type', 100)->comment('image/jpeg, image/png, image/heic, application/pdf');
            $table->unsignedBigInteger('file_size')->comment('Size in bytes');
            $table->string('checksum_sha256', 64)->nullable()->comment('Duplicate detection of the same photo');

            // ADR-6: WhatsApp photos from iPhone often arrive as HEIC, which browsers cannot render.
            $table->boolean('needs_conversion')->default(false);

            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('diagnostic_protocol_id', 'pa_protocol_idx');
            $table->index(['company_id', 'checksum_sha256'], 'pa_company_checksum_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_attachments');
    }
};
