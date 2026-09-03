<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Services\CaravanValueParser;
use PHPUnit\Framework\TestCase;

class CaravanValueParserTest extends TestCase
{
    public function test_parse_teeth_variations(): void
    {
        $this->assertEquals(0, CaravanValueParser::parseTeeth('DL'));
        $this->assertEquals(0, CaravanValueParser::parseTeeth('D.L.'));
        $this->assertEquals(0, CaravanValueParser::parseTeeth('d/l'));
        $this->assertEquals(0, CaravanValueParser::parseTeeth('Diente de Leche'));
        $this->assertEquals(0, CaravanValueParser::parseTeeth('Leche (0)'));
        $this->assertEquals(0, CaravanValueParser::parseTeeth('Sin dientes'));
        $this->assertEquals(0, CaravanValueParser::parseTeeth('0D'));

        $this->assertEquals(2, CaravanValueParser::parseTeeth('2D'));
        $this->assertEquals(2, CaravanValueParser::parseTeeth('2 d'));
        $this->assertEquals(2, CaravanValueParser::parseTeeth('2 dientes'));
        $this->assertEquals(2, CaravanValueParser::parseTeeth('dos dientes'));
        $this->assertEquals(2, CaravanValueParser::parseTeeth(2));

        $this->assertEquals(4, CaravanValueParser::parseTeeth('4D'));
        $this->assertEquals(4, CaravanValueParser::parseTeeth('4 dientes'));
        $this->assertEquals(4, CaravanValueParser::parseTeeth('Media Boca'));
        $this->assertEquals(4, CaravanValueParser::parseTeeth('media_boca'));
        $this->assertEquals(4, CaravanValueParser::parseTeeth('MB'));

        $this->assertEquals(6, CaravanValueParser::parseTeeth('6D'));
        $this->assertEquals(6, CaravanValueParser::parseTeeth('6 dientes'));

        $this->assertEquals(8, CaravanValueParser::parseTeeth('8D'));
        $this->assertEquals(8, CaravanValueParser::parseTeeth('Boca Llena'));
        $this->assertEquals(8, CaravanValueParser::parseTeeth('boca_llena'));
        $this->assertEquals(8, CaravanValueParser::parseTeeth('Full Mouth'));
        $this->assertEquals(8, CaravanValueParser::parseTeeth('BLL'));

        $this->assertEquals(0, CaravanValueParser::parseTeeth(null));
        $this->assertEquals(0, CaravanValueParser::parseTeeth(''));
    }

    public function test_parse_weight(): void
    {
        $this->assertEquals(315.0, CaravanValueParser::parseWeight('315.0'));
        $this->assertEquals(315.5, CaravanValueParser::parseWeight('315,5'));
        $this->assertEquals(1200.5, CaravanValueParser::parseWeight('1200.5'));
        $this->assertEquals(450.0, CaravanValueParser::parseWeight(450));
        $this->assertNull(CaravanValueParser::parseWeight(null));
        $this->assertNull(CaravanValueParser::parseWeight(''));
    }
}
