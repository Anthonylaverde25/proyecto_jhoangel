<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\UnitOfMeasureEntity;
use App\Core\Enums\UnitDimension;
use App\Core\Exceptions\ItemCatalogException;
use PHPUnit\Framework\TestCase;

class UnitOfMeasureEntityTest extends TestCase
{
    private function unit(?string $factor = '100', bool $isBase = false): UnitOfMeasureEntity
    {
        return new UnitOfMeasureEntity(1, 1, 'QQ', 'Quintal', 'qq', UnitDimension::MASS, $factor, $isBase);
    }

    public function test_the_factor_is_positive_and_the_base_equals_one(): void
    {
        $this->expectException(ItemCatalogException::class);
        $this->unit('0');
    }

    public function test_a_base_needs_factor_one(): void
    {
        $this->expectException(ItemCatalogException::class);
        $this->unit('100', true);
    }

    public function test_conversion_freezes_when_in_use_but_not_for_an_identical_value(): void
    {
        $unit = $this->unit();
        $unit->changeConversion(UnitDimension::MASS, '100.0000000000', 3);
        $this->assertSame('100', $unit->getFactorToBase());

        $this->expectException(ItemCatalogException::class);
        $unit->changeConversion(UnitDimension::MASS, '50', 3);
    }

    public function test_a_base_is_never_deactivated(): void
    {
        $this->expectException(ItemCatalogException::class);
        $this->unit('1', true)->setActive(false, 0);
    }
}
