<?php

declare(strict_types=1);

namespace Tests\Feature\ExitOrders;

use App\Core\Enums\PartnerRole;
use App\Core\ValueObjects\Cuit;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\Partner;
use Illuminate\Testing\TestResponse;
use Tests\Feature\BirthOrders\BirthOrderTestCase;
use Tests\Support\TestPartner;

/**
 * The breeding herd of the birth orders (pregnant females, calves at foot) plus a buyer: what an
 * exit order needs (egreso plan v5, 07 §3).
 */
abstract class ExitOrderTestCase extends BirthOrderTestCase
{
    protected Partner $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = $this->partner('Frigorífico de Prueba', [PartnerRole::CUSTOMER_LIVESTOCK]);
    }

    /** @param list<PartnerRole> $roles */
    protected function partner(string $name, array $roles, ?int $companyId = null): Partner
    {
        return TestPartner::create([
            'company_id' => $companyId ?? $this->company->id,
            'name' => $name,
            'cuit' => $this->freshCuit(),
            'roles' => $roles,
        ]);
    }

    protected function steer(string $tag, ?Batch $batch = null): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => ($batch ?? $this->breedingA)->id,
            'identification' => $tag,
            'sex' => 'M',
            'category_id' => $this->categoryId('NOVILLO'),
        ]);
    }

    /** An untagged calf at foot of a cow that already calved: [cow, calf]. */
    protected function cowWithCalf(string $cowTag): array
    {
        $cow = $this->pregnantFemale($cowTag);

        $response = $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [['caravan_id' => $cow->id, 'outcome' => 'LIVE', 'calf_sex' => 'M', 'birth_date' => now()->toDateString()]],
        ])->assertStatus(201);

        $calfId = collect($response->json('order.animals'))->firstWhere('caravan_id', $cow->id)['calf_caravan_id'];

        return [$cow->fresh(), Caravan::findOrFail($calfId)];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $overrides
     */
    protected function registerExit(array $lines, array $overrides = []): TestResponse
    {
        return $this->apiAs('POST', '/exit-orders/register', $overrides + [
            'buyer_partner_id' => $this->buyer->id,
            'exit_date' => now()->toDateString(),
            'lines' => $lines,
        ]);
    }

    /**
     * @param Caravan[] $animals
     * @param array<string, mixed> $overrides
     */
    protected function issue(array $animals, array $overrides = []): TestResponse
    {
        return $this->apiAs('POST', '/exit-orders', $overrides + [
            'buyer_partner_id' => $this->buyer->id,
            'exit_date' => now()->toDateString(),
            'caravan_ids' => array_map(fn (Caravan $c) => $c->id, $animals),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $overrides
     */
    protected function execute(int $orderId, array $lines, array $overrides = []): TestResponse
    {
        return $this->apiAs('POST', "/exit-orders/{$orderId}/execute", $overrides + [
            'exit_date' => now()->toDateString(),
            'lines' => $lines,
        ]);
    }

    /** @return array<string, mixed> */
    protected function line(Caravan $caravan, array $values = []): array
    {
        return ['caravan_id' => $caravan->id] + $values;
    }

    protected function exitBatchOf(Caravan $caravan): ?string
    {
        return Batch::withoutGlobalScopes()->with('batchType')->find($caravan->fresh()->batch_id)?->batchType?->code;
    }

    private function freshCuit(): string
    {
        do {
            $ten = '30' . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
            $expected = Cuit::fromString($ten . '0')->expectedCheckDigit();
            $digits = $ten . $expected;
        } while ($expected === null || Partner::withoutGlobalScopes()->where('cuit', $digits)->exists());

        return $digits;
    }
}
