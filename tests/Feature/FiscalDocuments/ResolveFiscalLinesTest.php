<?php

declare(strict_types=1);

namespace Tests\Feature\FiscalDocuments;

use App\Application\DTOs\ResolvedFiscalLine;
use App\Application\UseCases\FiscalDocuments\ResolveFiscalLinesUseCase;
use App\Core\Exceptions\FiscalReaderUnavailableException;
use App\Core\Interfaces\IFiscalItemMatcher;
use App\Core\ValueObjects\FiscalItemMatch;
use App\Core\ValueObjects\FiscalLineRead;
use App\Models\Item;
use Illuminate\Support\Str;
use Tests\Feature\Stock\StockTestCase;

/**
 * Resolving the item of each line read from a provider document (Delivery G, part 04 §2): EXACT by
 * name without the matcher, HIGH fills, MEDIUM only suggests, and nothing outside the company's active
 * catalog ever reaches a line. The matcher is faked and records what it was sent.
 */
class ResolveFiscalLinesTest extends StockTestCase
{
    /** @var array{lines: list<FiscalLineRead>, catalog: list<\App\Core\ValueObjects\FiscalCatalogItem>, provider: list<int>}|null */
    private ?array $sent = null;

    public function test_an_exact_name_resolves_without_the_matcher(): void
    {
        $name = 'Alambre liso ' . Str::random(6);
        $itemId = $this->createItem(['name' => $name, 'behavior' => 'EXPENSE_ARTICLE', 'unit_of_measure_id' => $this->unitId('UNIT')]);
        $this->matcherAnswers([]);

        [$line] = $this->resolve([new FiscalLineRead(1, mb_strtoupper($name) . '.', quantity: '2.5000')]);

        $this->assertSame([ResolvedFiscalLine::SOURCE_EXACT, 'EXACT', $itemId], [$line->source, $line->level, $line->itemId]);
        $this->assertNull($this->sent, 'Every line was exact: the matcher is not called.');
    }

    public function test_two_items_with_the_same_name_are_not_exact(): void
    {
        $name = 'Homónimo ' . Str::random(6);
        $this->createItem(['name' => $name]);
        $this->createItem(['name' => $name . '.']);
        $this->matcherAnswers([]);

        [$line] = $this->resolve([new FiscalLineRead(1, $name)]);

        $this->assertSame(ResolvedFiscalLine::SOURCE_NONE, $line->source);
        $this->assertNotNull($this->sent);
    }

    public function test_a_size_in_the_description_is_not_exact(): void
    {
        $name = 'Ivermectina ' . Str::random(6);
        $this->createItem(['name' => $name]);
        $this->matcherAnswers([]);

        [$line] = $this->resolve([new FiscalLineRead(1, $name . ' x 500 ml')]);

        $this->assertSame(ResolvedFiscalLine::SOURCE_NONE, $line->source);
    }

    public function test_high_fills_medium_suggests_and_none_leaves_empty(): void
    {
        [$ivermectin] = $this->stockedItem('Ivermectina ' . Str::random(6));
        $bale = $this->createItem(['name' => 'Rollo de avena ' . Str::random(6), 'unit_of_measure_id' => $this->unitId('UNIT'), 'behavior' => 'STOCKED_ARTICLE']);
        $this->matcherAnswers([
            new FiscalItemMatch(1, $ivermectin, FiscalItemMatch::HIGH, 'Abreviatura'),
            new FiscalItemMatch(2, $bale, FiscalItemMatch::MEDIUM, 'No dice que sea rollo'),
            new FiscalItemMatch(3, null, FiscalItemMatch::NONE, 'No está en el catálogo'),
        ]);

        [$high, $medium, $none] = $this->resolve([
            new FiscalLineRead(1, 'Ivermec 1% x 500cc'),
            new FiscalLineRead(2, 'Avena', 'AV'),
            new FiscalLineRead(3, 'Poste quebracho 2,20 m'),
        ]);

        $this->assertSame([ResolvedFiscalLine::SOURCE_AI, 'HIGH', $ivermectin, null], [$high->source, $high->level, $high->itemId, $high->suggestedItemId]);
        $this->assertSame([ResolvedFiscalLine::SOURCE_NONE, 'MEDIUM', null, $bale], [$medium->source, $medium->level, $medium->itemId, $medium->suggestedItemId]);
        $this->assertSame([ResolvedFiscalLine::SOURCE_NONE, 'NONE', null, null], [$none->source, $none->level, $none->itemId, $none->suggestedItemId]);
        $this->assertSame('No dice que sea rollo', $medium->reason);
    }

    public function test_no_codes_reach_the_matcher_and_inactive_items_are_not_sent(): void
    {
        $inactive = $this->createItem(['name' => 'Discontinuado ' . Str::random(6)]);
        Item::withoutGlobalScopes()->whereKey($inactive)->update(['is_active' => false]);
        $this->matcherAnswers([new FiscalItemMatch(1, $inactive, FiscalItemMatch::HIGH, 'Inventado')]);

        [$line] = $this->resolve([new FiscalLineRead(1, 'Garrapaticida viejo', 'ART-0005')]);

        $sentIds = array_map(fn ($item) => $item->id, $this->sent['catalog']);
        $this->assertNotContains($inactive, $sentIds);
        $this->assertSame(ResolvedFiscalLine::SOURCE_NONE, $line->source, 'An id outside the catalog sent never reaches a line.');
        $this->assertNull($line->itemId);
        $this->assertObjectNotHasProperty('code', $this->sent['catalog'][0]);
    }

    public function test_the_providers_items_widen_the_candidates(): void
    {
        [$itemId] = $this->stockedItem('Vacuna ' . Str::random(6));
        \App\Models\ItemProvider::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'item_id' => $itemId, 'provider_id' => $this->seller->id, 'is_preferred' => true,
        ]);
        $this->matcherAnswers([]);

        $this->resolve([new FiscalLineRead(1, 'Vac. antiaftosa')], $this->seller->id);

        $this->assertSame([$itemId], $this->sent['provider']);
    }

    public function test_quantity_price_and_vat_come_through_and_exempt_is_not_zero(): void
    {
        $this->matcherAnswers([]);

        [$exempt, $tenAndHalf, $unknown] = $this->resolve([
            new FiscalLineRead(1, 'Poste', quantity: '40.0000', unitPrice: '7600.000000', vatRateCode: 'VAT_EXEMPT'),
            new FiscalLineRead(2, 'Avena', vatRateCode: 'VAT_10_5'),
            new FiscalLineRead(3, 'Otra', vatRateCode: 'VAT_99'),
        ]);

        $this->assertSame($this->rateId('VAT_EXEMPT'), $exempt->vatTaxRateId);
        $this->assertNotSame($this->rateId('VAT_0'), $exempt->vatTaxRateId);
        $this->assertSame(['40.0000', '7600.000000'], [$exempt->read->quantity, $exempt->read->unitPrice]);
        $this->assertSame($this->rateId('VAT_10_5'), $tenAndHalf->vatTaxRateId);
        $this->assertNull($unknown->vatTaxRateId);
    }

    public function test_a_matcher_failure_leaves_the_lines_unresolved_with_a_note(): void
    {
        $this->app->instance(IFiscalItemMatcher::class, new class implements IFiscalItemMatcher {
            public function match(array $lines, array $catalog, array $providerItemIds, int $companyId): array
            {
                throw FiscalReaderUnavailableException::unreachable();
            }
        });

        [$line] = $this->resolve([new FiscalLineRead(1, 'Algo que no es exacto ' . Str::random(4), quantity: '3.0000')]);

        $this->assertSame([ResolvedFiscalLine::SOURCE_NONE, ResolvedFiscalLine::NOTE_MATCHER_UNAVAILABLE, '3.0000'], [$line->source, $line->note, $line->read->quantity]);
    }

    public function test_a_line_without_code_resolves_the_same(): void
    {
        $name = 'Sal mineral ' . Str::random(6);
        $itemId = $this->createItem(['name' => $name, 'unit_of_measure_id' => $this->unitId('KG')]);
        $this->matcherAnswers([]);

        [$line] = $this->resolve([new FiscalLineRead(1, $name, null)]);

        $this->assertSame($itemId, $line->itemId);
    }

    /**
     * @param list<FiscalLineRead> $lines
     * @return list<ResolvedFiscalLine>
     */
    private function resolve(array $lines, ?int $providerId = null): array
    {
        return app(ResolveFiscalLinesUseCase::class)($lines, $providerId, $this->company->id);
    }

    /** @param list<FiscalItemMatch> $matches */
    private function matcherAnswers(array $matches): void
    {
        $test = $this;
        $this->app->instance(IFiscalItemMatcher::class, new class($matches, function (array $sent) use ($test) {
            $test->sent = $sent;
        }) implements IFiscalItemMatcher {
            /** @param list<FiscalItemMatch> $matches */
            public function __construct(private readonly array $matches, private readonly \Closure $record)
            {
            }

            public function match(array $lines, array $catalog, array $providerItemIds, int $companyId): array
            {
                ($this->record)(['lines' => $lines, 'catalog' => $catalog, 'provider' => $providerItemIds]);

                return $this->matches;
            }
        });
    }
}
