<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Enums\ReproductiveStatus;
use PHPUnit\Framework\TestCase;

class ReproductiveStatusTest extends TestCase
{
    public function test_a_category_that_does_not_breed_is_not_applicable_whatever_the_owner_said(): void
    {
        $this->assertSame(ReproductiveStatus::NOT_APPLICABLE, ReproductiveStatus::of(false, true));
        $this->assertSame(ReproductiveStatus::NOT_APPLICABLE, ReproductiveStatus::of(false, false));
    }

    public function test_a_breeding_category_follows_the_owners_decision(): void
    {
        $this->assertSame(ReproductiveStatus::ELIGIBLE, ReproductiveStatus::of(true, true));
        $this->assertSame(ReproductiveStatus::EXCLUDED, ReproductiveStatus::of(true, false));
    }

    public function test_a_caravan_without_category_is_treated_as_reproductive(): void
    {
        $this->assertSame(ReproductiveStatus::ELIGIBLE, ReproductiveStatus::of(null, true));
        $this->assertSame(ReproductiveStatus::EXCLUDED, ReproductiveStatus::of(null, false));
    }
}
