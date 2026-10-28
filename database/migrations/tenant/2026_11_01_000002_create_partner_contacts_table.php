<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MIGRATED_CONTACT_NAME = 'Principal';

    /**
     * Contact details move from the partner to the people we talk to. A partner may have none;
     * when it has some, exactly one is the default (the one lists, exports and the assistant use).
     * The phone is mandatory, so a legacy row with an email and no phone stops the migration.
     */
    public function up(): void
    {
        $emailOnly = DB::table('partners')
            ->whereNotNull('email')->where('email', '<>', '')
            ->where(fn (Builder $query) => $query->whereNull('phone')->orWhere('phone', ''))
            ->pluck('id');

        if ($emailOnly->isNotEmpty()) {
            throw new RuntimeException(
                'Partners with an email and no phone (ids: '.$emailOnly->implode(', ').'). '
                .'Fill in their phone before migrating: a partner contact requires one.'
            );
        }

        Schema::create('partner_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('partner_id');
            $table->string('name', 120);
            $table->string('position', 120)->nullable();
            $table->string('phone', 50);
            $table->string('email')->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['partner_id', 'is_default']);

            $table->foreign(['partner_id', 'company_id'])
                ->references(['id', 'company_id'])->on('partners')->cascadeOnDelete();
        });

        // At most one default per partner (C7), same technique as uq_wh_one_default.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX uq_pc_one_default ON partner_contacts (partner_id) WHERE is_default = 1');
        } else {
            DB::statement('CREATE UNIQUE INDEX uq_pc_one_default ON partner_contacts ((IF(is_default, partner_id, NULL)))');
            DB::statement("ALTER TABLE partner_contacts ADD CONSTRAINT chk_pc_phone CHECK (TRIM(phone) <> '')");
        }

        DB::table('partner_contacts')->insertUsing(
            ['company_id', 'partner_id', 'name', 'phone', 'email', 'is_default', 'created_at', 'updated_at'],
            DB::table('partners')
                ->whereNotNull('phone')->where('phone', '<>', '')
                ->select([
                    'company_id',
                    'id',
                    DB::raw("'".self::MIGRATED_CONTACT_NAME."'"),
                    'phone',
                    DB::raw("NULLIF(email, '')"),
                    DB::raw('1'),
                    DB::raw('CURRENT_TIMESTAMP'),
                    DB::raw('CURRENT_TIMESTAMP'),
                ])
        );

        Schema::table('partners', function (Blueprint $table) {
            $table->dropUnique('uq_providers_company_email');
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['email', 'phone']);
        });
    }

    /**
     * Only the default contact fits back into the partner row; any other contact is lost.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('email')->nullable()->after('location');
            $table->string('phone', 50)->nullable()->after('email');
        });

        DB::table('partner_contacts')
            ->where('is_default', true)
            ->orderBy('id')
            ->each(function (object $contact): void {
                DB::table('partners')->where('id', $contact->partner_id)->update([
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                ]);
            });

        Schema::table('partners', function (Blueprint $table) {
            $table->unique(['company_id', 'email'], 'uq_providers_company_email');
        });

        Schema::dropIfExists('partner_contacts');
    }
};
