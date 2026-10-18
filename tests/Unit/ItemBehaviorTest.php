<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Enums\ItemBehavior;
use App\Core\Enums\ItemType;
use PHPUnit\Framework\TestCase;

class ItemBehaviorTest extends TestCase
{
    public function test_each_behavior_round_trips_through_type_and_stock(): void
    {
        foreach (ItemBehavior::cases() as $behavior) {
            $this->assertSame($behavior, ItemBehavior::of($behavior->type(), $behavior->tracksStock()));
        }
    }

    public function test_a_service_is_a_service_whatever_the_flag(): void
    {
        $this->assertSame(ItemBehavior::SERVICE, ItemBehavior::of(ItemType::SERVICE, true));
        $this->assertFalse(ItemBehavior::SERVICE->tracksStock());
    }
}
