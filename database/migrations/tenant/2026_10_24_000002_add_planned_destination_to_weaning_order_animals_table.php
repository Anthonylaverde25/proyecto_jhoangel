<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the order planned for the calf, never rewritten. weaning_order_destination_id keeps
        // where it actually went: they differ when the weaning declared another destination.
        Schema::table('weaning_order_animals', function (Blueprint $table) {
            $table->foreignId('planned_destination_id')->nullable()->after('weaning_order_destination_id')
                ->constrained('weaning_order_destinations')->onDelete('set null');
            $table->index(['weaning_order_id', 'planned_destination_id'], 'woa_order_planned_idx');
        });

        // Lines from before: nothing could have been redeclared yet, so the plan is what they have.
        DB::table('weaning_order_animals')->update(['planned_destination_id' => DB::raw('weaning_order_destination_id')]);
    }

    public function down(): void
    {
        Schema::table('weaning_order_animals', function (Blueprint $table) {
            $table->dropIndex('woa_order_planned_idx');
            $table->dropConstrainedForeignId('planned_destination_id');
        });
    }
};
