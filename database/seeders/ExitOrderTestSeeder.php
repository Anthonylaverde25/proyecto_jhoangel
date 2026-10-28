<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\DTOs\ExitOrders\ExitLineDTO;
use App\Application\DTOs\ExitOrders\IssueExitOrderDTO;
use App\Application\DTOs\ExitOrders\RegisterExitDTO;
use App\Application\UseCases\ExitOrders\IssueExitOrderUseCase;
use App\Application\UseCases\ExitOrders\RegisterExitUseCase;
use App\Core\Enums\PartnerRole;
use App\Core\Exceptions\FieldValidationException;
use App\Core\Interfaces\ICompanyContext;
use App\Core\ValueObjects\TerminalBatchTypes;
use App\Models\Company;
use App\Models\ExitOrder;
use Database\Seeders\Concerns\SeedsPartners;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Exit orders scenario (egreso plan v5, 07 §3), first company, fake data. Creates only what is
 * missing and loads through the use cases; an order is found again by its seed key in the
 * observations, so seeding twice duplicates nothing and an operated base is not touched.
 *
 * - "Frigorífico de Prueba" (buys livestock) with two contact persons, and "Productor Vecino de
 *   Prueba" (sells livestock, buys livestock and articles).
 * - seed-eg-01: an issued order with four animals of two activities.
 * - seed-eg-02: a registered exit, already executed, without DTE.
 */
class ExitOrderTestSeeder extends Seeder
{
    use SeedsPartners;

    private const FRIGORIFICO_CUIT = '30718000501';
    private const VECINO_CUIT = '30718000528';

    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        if ($company === null) {
            return;
        }
        $companyId = (int) $company->id;
        app(ICompanyContext::class)->setCompanyId($companyId);

        $frigorifico = $this->seedPartner($companyId, self::FRIGORIFICO_CUIT, ['name' => 'Frigorífico de Prueba', 'location' => 'Ruta 3 km 380, Azul'], [PartnerRole::CUSTOMER_LIVESTOCK], [
            'name' => 'Comprador', 'phone' => '2281 400100', 'email' => 'compras@frigorifico-prueba.test',
        ]);
        if (DB::table('partner_contacts')->where('partner_id', $frigorifico->id)->count() === 1) {
            DB::table('partner_contacts')->insert([
                'company_id' => $companyId, 'partner_id' => $frigorifico->id, 'name' => 'Administración', 'position' => 'Liquidaciones',
                'phone' => '2281 400200', 'email' => 'administracion@frigorifico-prueba.test', 'is_default' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->seedPartner($companyId, self::VECINO_CUIT, ['name' => 'Productor Vecino de Prueba', 'location' => 'Paraje La Numancia, Tandil'], [
            PartnerRole::SUPPLIER_LIVESTOCK, PartnerRole::CUSTOMER_LIVESTOCK, PartnerRole::CUSTOMER_ITEMS,
        ], ['name' => 'Principal', 'phone' => '2494 555777']);

        $free = $this->freeAnimalsByActivity($companyId);

        if (!$this->exists('seed-eg-01', $companyId)) {
            $roll = array_merge(array_slice($free[0] ?? [], 0, 2), array_slice($free[1] ?? [], 0, 2));
            $this->attempt('seed-eg-01', fn () => app(IssueExitOrderUseCase::class)(new IssueExitOrderDTO(
                $companyId, null, (int) $frigorifico->id, now()->addDays(3)->toDateString(), $roll, 'Encargado de prueba', 'seed-eg-01 · Carga de prueba'
            )));
        }

        if (!$this->exists('seed-eg-02', $companyId)) {
            $sold = array_slice($free[0] ?? [], 2, 1);
            $this->attempt('seed-eg-02', fn () => app(RegisterExitUseCase::class)(new RegisterExitDTO(
                $companyId, null, (int) $frigorifico->id, now()->toDateString(),
                array_map(fn (int $id) => new ExitLineDTO($id, 405.0, 2100.0), $sold),
                null, null, 'Encargado de prueba', 'seed-eg-02 · Venta de prueba'
            )));
        }
    }

    private function exists(string $key, int $companyId): bool
    {
        return ExitOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('observations', 'like', "{$key}%")->exists();
    }

    /**
     * Live males of own batches, without calves and not held by any order, grouped by the activity
     * of their batch: two groups, the largest first.
     *
     * @return list<list<int>>
     */
    private function freeAnimalsByActivity(int $companyId): array
    {
        $rows = DB::table('caravans')
            ->join('batches', 'batches.id', '=', 'caravans.batch_id')
            ->leftJoin('batch_types', 'batch_types.id', '=', 'batches.batch_type_id')
            ->leftJoin('caravan_deaths', 'caravan_deaths.caravan_id', '=', 'caravans.id')
            ->where('caravans.company_id', $companyId)
            ->where('caravans.sex', 'M')
            ->whereNotNull('caravans.identification')
            ->whereNull('batches.farm_id')
            ->whereNull('caravan_deaths.id')
            ->where(fn ($q) => $q->whereNull('batch_types.code')->orWhereNotIn('batch_types.code', [...TerminalBatchTypes::ALL, 'INTERNAL_CONSUMPTION']))
            ->whereNotExists(fn ($q) => $q->from('caravan_lineage')->whereColumn('caravan_lineage.caravan_id', 'caravans.id')->where('caravan_lineage.is_nursing', true))
            ->whereNotExists(fn ($q) => $q->from('transfer_order_animals')->whereColumn('transfer_order_animals.caravan_id', 'caravans.id')->where('transfer_order_animals.status', 'PENDING'))
            ->whereNotExists(fn ($q) => $q->from('weaning_order_animals')->whereColumn('weaning_order_animals.caravan_id', 'caravans.id')->where('weaning_order_animals.status', 'PENDING'))
            ->whereNotExists(fn ($q) => $q->from('exit_order_animals')->whereColumn('exit_order_animals.caravan_id', 'caravans.id')->whereIn('exit_order_animals.status', ['PENDING', 'EXITED']))
            ->whereNotExists(fn ($q) => $q->from('service_order_males')->whereColumn('service_order_males.male_caravan_id', 'caravans.id'))
            ->orderBy('caravans.id')
            ->get(['caravans.id', 'batches.activity_id']);

        $groups = $rows->groupBy('activity_id')->map(fn ($group) => $group->pluck('id')->map(fn ($id) => (int) $id)->all())->sortByDesc(fn ($ids) => count($ids));

        return array_values($groups->all());
    }

    private function attempt(string $key, callable $load): void
    {
        try {
            $load();
        } catch (FieldValidationException $e) {
            $this->command?->warn("ExitOrderTestSeeder {$key}: {$e->getMessage()}");
        }
    }
}
