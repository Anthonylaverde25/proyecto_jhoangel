<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Services\CategoryTreeGuard;
use PHPUnit\Framework\TestCase;

class CategoryTreeGuardTest extends TestCase
{
    /** Health(1) > Antiparasitics(2) > Endectocides(3); Nutrition(4) */
    private const TREE = [1 => null, 2 => 1, 3 => 2, 4 => null];

    public function test_a_category_cannot_hang_from_itself_or_a_descendant(): void
    {
        $this->assertTrue(CategoryTreeGuard::createsCycle(self::TREE, 2, 2));
        $this->assertTrue(CategoryTreeGuard::createsCycle(self::TREE, 1, 3));
    }

    public function test_moving_to_another_branch_or_the_root_is_fine(): void
    {
        $this->assertFalse(CategoryTreeGuard::createsCycle(self::TREE, 2, 4));
        $this->assertFalse(CategoryTreeGuard::createsCycle(self::TREE, 3, null));
    }
}
