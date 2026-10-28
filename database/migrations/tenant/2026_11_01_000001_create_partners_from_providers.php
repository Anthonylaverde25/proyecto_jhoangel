<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Legacy role column => PartnerRole code. */
    private const SUPPLIER_COLUMNS = [
        'supplies_livestock' => 'SUPPLIER_LIVESTOCK',
        'supplies_items' => 'SUPPLIER_ITEMS',
    ];

    /**
     * Providers become partners: a third party that may supply, buy, or both. The roles move
     * from two boolean columns to one row each, so a new role is an enum value, not a migration.
     * Ids are kept, so every provider_id column keeps pointing at the same row. Index names
     * (uq_providers_*) are kept too: renaming them means rebuilding the composite FK targets in
     * SQLite for no functional gain.
     */
    public function up(): void
    {
        // The "at least one role" CHECK cannot span tables; PartnerEntity enforces it from now on.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE providers DROP CHECK chk_providers_role');
        }

        Schema::rename('providers', 'partners');

        Schema::create('partner_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('partner_id');

            // PartnerRole: SUPPLIER_LIVESTOCK | SUPPLIER_ITEMS | CUSTOMER_LIVESTOCK | CUSTOMER_ITEMS
            $table->string('role', 32);
            $table->timestamps();

            $table->unique(['partner_id', 'role']);
            // Selectors list the partners of a company holding a role.
            $table->index(['company_id', 'role']);

            $table->foreign(['partner_id', 'company_id'])
                ->references(['id', 'company_id'])->on('partners')->cascadeOnDelete();
        });

        foreach (self::SUPPLIER_COLUMNS as $column => $role) {
            DB::table('partner_roles')->insertUsing(
                ['company_id', 'partner_id', 'role', 'created_at', 'updated_at'],
                DB::table('partners')
                    ->where($column, true)
                    ->select([
                        'company_id',
                        'id',
                        DB::raw("'{$role}'"),
                        DB::raw('CURRENT_TIMESTAMP'),
                        DB::raw('CURRENT_TIMESTAMP'),
                    ])
            );
        }

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::SUPPLIER_COLUMNS));
        });
    }

    public function down(): void
    {
        $legacy = array_values(self::SUPPLIER_COLUMNS);

        if (DB::table('partner_roles')->whereNotIn('role', $legacy)->exists()) {
            throw new RuntimeException('Cannot roll back: there are customer roles the provider schema cannot hold.');
        }

        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('supplies_livestock')->default(false)->after('phone');
            $table->boolean('supplies_items')->default(false)->after('supplies_livestock');
        });

        foreach (self::SUPPLIER_COLUMNS as $column => $role) {
            DB::table('partners')
                ->whereIn('id', fn (Builder $query) => $query
                    ->select('partner_id')->from('partner_roles')->where('role', $role))
                ->update([$column => true]);
        }

        Schema::dropIfExists('partner_roles');
        Schema::rename('partners', 'providers');

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE providers ADD CONSTRAINT chk_providers_role
                CHECK (supplies_livestock = 1 OR supplies_items = 1)');
        }
    }
};
