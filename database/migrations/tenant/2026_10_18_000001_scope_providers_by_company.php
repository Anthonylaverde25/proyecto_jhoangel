<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Providers become company-scoped: each company manages its own portfolio, and the same
 * CUIT may exist once per company. Adds the declared supplier role (livestock / items).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id');
            $table->boolean('supplies_livestock')->default(true)->after('phone');
            $table->boolean('supplies_items')->default(false)->after('supplies_livestock');
        });

        $this->backfillCompany();

        Schema::table('providers', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->dropUnique('providers_cuit_unique');
            $table->dropUnique('providers_email_unique');
            $table->unique(['company_id', 'cuit'], 'uq_providers_company_cuit');
            $table->unique(['company_id', 'email'], 'uq_providers_company_email');
            // Target of the company-scoped composite foreign keys.
            $table->unique(['id', 'company_id'], 'uq_providers_id_company');
        });

        // At least one role; ProviderEntity enforces the same rule (SQLite has no CHECK here).
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE providers ADD CONSTRAINT chk_providers_role
                CHECK (supplies_livestock = 1 OR supplies_items = 1)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE providers DROP CHECK chk_providers_role');
        }

        Schema::table('providers', function (Blueprint $table) {
            $table->dropUnique('uq_providers_id_company');
            $table->dropUnique('uq_providers_company_email');
            $table->dropUnique('uq_providers_company_cuit');
            // Fails on purpose if two companies registered the same CUIT after migrating.
            $table->unique('cuit', 'providers_cuit_unique');
            $table->unique('email', 'providers_email_unique');
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'supplies_livestock', 'supplies_items']);
        });
    }

    private function backfillCompany(): void
    {
        $usages = DB::query()->fromSub(
            DB::table('farms')->select('provider_id', 'company_id')->whereNotNull('provider_id')
                ->union(DB::table('entry_orders')->select('provider_id', 'company_id'))
                ->union(DB::table('caravans')->select('provider_id', 'company_id')->whereNotNull('provider_id'))
                ->union(DB::table('caravan_movements')->select('provider_id', 'company_id')->whereNotNull('provider_id')),
            'u'
        )->whereNotNull('company_id')->distinct()->get()->groupBy('provider_id');

        $shared = $usages->filter(fn ($rows) => $rows->count() > 1)->keys();
        if ($shared->isNotEmpty()) {
            throw new RuntimeException(
                'Providers used by more than one company must be split manually first: ids '
                . $shared->implode(', ')
            );
        }

        $fallbackCompanyId = DB::table('companies')->min('id');

        foreach (DB::table('providers')->pluck('id') as $providerId) {
            $companyId = $usages->get($providerId)?->first()->company_id ?? $fallbackCompanyId;
            if ($companyId === null) {
                throw new RuntimeException('Providers exist but there is no company to assign them to.');
            }
            DB::table('providers')->where('id', $providerId)->update(['company_id' => $companyId]);
        }
    }
};
