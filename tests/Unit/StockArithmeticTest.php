<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\ItemPresentationEntity;
use App\Core\Entities\UnitOfMeasureEntity;
use App\Core\Enums\UnitDimension;
use App\Core\Services\FiscalDocumentCalculator;
use App\Core\Services\QuantityConverter;
use App\Core\ValueObjects\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * Exact decimals, the paper-to-item-unit conversion (plan 4.4) and the line amounts (plan 4.5).
 */
class StockArithmeticTest extends TestCase
{
    public function test_decimals_round_half_up_to_the_column_scale(): void
    {
        $this->assertSame('0.3111', Decimal::of('0.311085')->toScale(4));
        $this->assertSame('311.080000', Decimal::of('7777')->mul(Decimal::of('4'))->div(Decimal::of('100'))->toScale(6));
        $this->assertSame('-25.0000', Decimal::of('-25')->toScale(4));
        $this->assertTrue(Decimal::of('0.00000')->isZero());
    }

    public function test_the_paper_converts_to_the_item_unit(): void
    {
        $converter = new QuantityConverter();
        $ml = $this->unit(1, 'ML', UnitDimension::VOLUME, '0.001');
        $litre = $this->unit(2, 'L', UnitDimension::VOLUME, '1');
        $dose = $this->unit(3, 'DOSE', UnitDimension::COUNT, null);
        $bottle = new ItemPresentationEntity(9, 1, 'Frasco x 500 ml', '500');

        $this->assertSame('5000.0000', $converter->toItemUnit(Decimal::of('10'), $ml, null, $bottle)?->toScale(4));
        $this->assertSame('2000.0000', $converter->toItemUnit(Decimal::of('2'), $ml, $litre, null)?->toScale(4));
        $this->assertSame('3.0000', $converter->toItemUnit(Decimal::of('3'), $ml, $ml, null)?->toScale(4));
        $this->assertNull($converter->toItemUnit(Decimal::of('5'), $dose, $litre, null));
    }

    public function test_the_line_amount_is_the_paper_and_the_unit_cost_has_six_decimals(): void
    {
        $calculator = new FiscalDocumentCalculator();
        $amount = $calculator->lineAmount(Decimal::of('4'), Decimal::of('7777'));

        $this->assertSame('31108.0000', $amount->toScale(4));
        $this->assertSame('3266.3400', $calculator->percentage($amount, Decimal::of('10.5'))->toScale(4));
        $this->assertSame('311.080000', $calculator->unitCost($amount, Decimal::of('100'))->toScale(6));
        $this->assertSame('0.311080', $calculator->unitCost($amount, Decimal::of('100000'))->toScale(6));
    }

    private function unit(int $id, string $code, UnitDimension $dimension, ?string $factor): UnitOfMeasureEntity
    {
        return new UnitOfMeasureEntity($id, 1, $code, $code, strtolower($code), $dimension, $factor, $factor === '1');
    }
}
