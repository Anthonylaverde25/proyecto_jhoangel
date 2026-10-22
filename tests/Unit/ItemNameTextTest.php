<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValueObjects\ItemNameText;
use PHPUnit\Framework\TestCase;

/** The comparable form of names for the EXACT level (Delivery G, G12). */
class ItemNameTextTest extends TestCase
{
    public function test_case_accents_and_punctuation_do_not_matter(): void
    {
        $this->assertTrue(ItemNameText::of('ALAMBRE LISO 17/15.')->equals(ItemNameText::of('Alambre liso 17/15')));
        $this->assertTrue(ItemNameText::of('Vacuna  ANTIAFTOSA (Ñandú)')->equals(ItemNameText::of('vacuna antiaftosa nandu')));
    }

    public function test_sizes_and_extra_words_do_matter(): void
    {
        $this->assertFalse(ItemNameText::of('Ivermectina 1% x 500 ml')->equals(ItemNameText::of('Ivermectina 1%')));
        $this->assertFalse(ItemNameText::of('Avena')->equals(ItemNameText::of('Rollo de avena')));
    }

    public function test_blank_never_equals(): void
    {
        $this->assertFalse(ItemNameText::of(' - ')->equals(ItemNameText::of('.')));
    }
}
