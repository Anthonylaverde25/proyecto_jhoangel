<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-blocking notice raised when a bull turns out positive to a contagious, disqualifying
 * pathogen: which females shared a service with him inside the risk window, so they can be
 * followed at pregnancy check. One alert per bull, pathogen and positive finding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanitary_exposure_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bull_caravan_id')->constrained('caravans')->restrictOnDelete();
            $table->string('pathogen_code', 60);
            $table->string('pathogen_name', 120)->nullable();
            $table->date('detected_on');                       // date of the positive finding
            $table->date('risk_window_from');                  // last negative, or detected_on - validity_days
            $table->text('detail');                            // sentence shown, built when raised

            $table->string('status', 10)->default('OPEN');     // OPEN | RESOLVED
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['bull_caravan_id', 'pathogen_code', 'detected_on'], 'uq_sea_bull_pathogen_finding');
            $table->index(['company_id', 'status'], 'idx_sea_company_status');
        });

        Schema::create('sanitary_exposure_alert_females', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // Explicit name: the default one exceeds MySQL's 64-character identifier limit.
            $table->foreignId('sanitary_exposure_alert_id')
                ->constrained('sanitary_exposure_alerts', 'id', 'fk_seaf_alert')
                ->cascadeOnDelete();
            $table->foreignId('female_caravan_id')->constrained('caravans')->restrictOnDelete();
            $table->foreignId('service_order_id')->constrained('service_orders')->restrictOnDelete();
            $table->date('overlap_from');
            $table->date('overlap_until')->nullable();         // null: still with the bull when raised

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 255)->nullable();
            $table->timestamps();

            $table->unique(
                ['sanitary_exposure_alert_id', 'female_caravan_id', 'service_order_id'],
                'uq_seaf_alert_female_order'
            );
            $table->index(['female_caravan_id', 'reviewed_at'], 'idx_seaf_female_review');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanitary_exposure_alert_females');
        Schema::dropIfExists('sanitary_exposure_alerts');
    }
};
