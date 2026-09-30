<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Already registered animals, tagged with ISO 11784 electronic numbers, for the chute reader
 * simulator in `mobile-scanner/tools/reader-simulator/`.
 *
 *  - 032000000000001..010: own animals of the first company (odd = female), in the batch
 *    "Lote Testing Lector BLE". The app must flag them as ALREADY REGISTERED.
 *  - 032000000000011..012: animals of "Hacienda Secundaria". Logged into the first company, the
 *    app must flag them as BELONGING TO ANOTHER COMPANY and never transfer them.
 *
 * The simulator lists the same numbers in `tools/reader-simulator/fixtures/existing_caravans.csv`;
 * keep both in step. The numbers it generates for new animals start at 032000000100001, so they
 * never collide with these.
 *
 * Re-running it is safe: every animal is put back in its company and batch.
 *     php artisan tenants:seed --class=BleReaderTestCaravansSeeder
 */
class BleReaderTestCaravansSeeder extends Seeder
{
    private const OWN_BATCH_NAME = 'Lote Testing Lector BLE';
    private const OTHER_BATCH_NAME = 'Lote Testing Lector BLE (Secundaria)';
    private const OTHER_COMPANY_NAME = 'Hacienda Secundaria';

    private const OWN_FIRST = 1;
    private const OWN_LAST = 10;
    private const OTHER_FIRST = 11;
    private const OTHER_LAST = 12;

    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('BleReaderTestCaravansSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $calfCategoryId = AnimalCategory::withoutGlobalScopes()->where('code', 'TERNERO')->value('id');

        $ownBatch = $this->resolveBatch((int) $company->id, self::OWN_BATCH_NAME);
        for ($n = self::OWN_FIRST; $n <= self::OWN_LAST; $n++) {
            $this->seedAnimal((int) $company->id, $n, (int) $ownBatch->id, $calfCategoryId);
        }

        $otherCompany = Company::where('name', self::OTHER_COMPANY_NAME)->where('id', '!=', $company->id)->first();

        if (!$otherCompany) {
            $this->command?->warn(sprintf(
                'BleReaderTestCaravansSeeder: no existe "%s"; se omiten las caravanas de otra empresa.',
                self::OTHER_COMPANY_NAME
            ));

            return;
        }

        $otherBatch = $this->resolveBatch((int) $otherCompany->id, self::OTHER_BATCH_NAME);
        for ($n = self::OTHER_FIRST; $n <= self::OTHER_LAST; $n++) {
            $this->seedAnimal((int) $otherCompany->id, $n, (int) $otherBatch->id, $calfCategoryId);
        }

        $this->command?->info(sprintf(
            'BleReaderTestCaravansSeeder: %s..%s en "%s" y %s..%s en "%s".',
            self::identification(self::OWN_FIRST),
            self::identification(self::OWN_LAST),
            $company->name,
            self::identification(self::OTHER_FIRST),
            self::identification(self::OTHER_LAST),
            $otherCompany->name
        ));
    }

    public static function identification(int $n): string
    {
        return sprintf('032%012d', $n);
    }

    private function resolveBatch(int $companyId, string $name): Batch
    {
        $activity = Activity::withoutGlobalScopes()->where('code', 'CRIA')->first();
        $operational = BatchType::withoutGlobalScopes()->where('code', 'OPERATIONAL')->first();

        return Batch::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'name' => $name],
            [
                'farm_id' => null,
                'activity_id' => $activity?->id,
                'batch_type_id' => $operational?->id,
                'is_active' => true,
                'is_system' => false,
                'observaciones' => 'Animales ya registrados que el simulador del lector BLE vuelve a leer.',
            ]
        );
    }

    private function seedAnimal(int $companyId, int $n, int $batchId, ?int $categoryId): void
    {
        // Keyed on the tag alone: the tag is unique across the tenant, and a test that
        // transferred one of them must not leave a second row behind on the next run.
        Caravan::withoutGlobalScopes()->updateOrCreate(
            ['identification' => self::identification($n)],
            [
                'company_id' => $companyId,
                'batch_id' => $batchId,
                'renspa' => 'NO_DEFINIDO',
                'sex' => $n % 2 === 1 ? 'H' : 'M',
                'teeth' => 0,
                'category_id' => $categoryId,
            ]
        );
    }
}
