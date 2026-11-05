<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\CastrationMethod;
use App\Core\Enums\CastrationStatus;
use App\Core\Services\CastrationPolicy;
use App\Core\ValueObjects\CastrationRecord;
use PHPUnit\Framework\TestCase;

final class CastrationPolicyTest extends TestCase
{
    private CastrationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CastrationPolicy();
    }

    public function test_status_for_female_returns_null(): void
    {
        $this->assertNull($this->policy->status(AnimalSex::FEMALE, 'TERNERO', false));
        $this->assertNull($this->policy->status(AnimalSex::FEMALE, 'VACA', true));
    }

    public function test_status_for_steer_categories_is_always_castrated(): void
    {
        $this->assertSame(CastrationStatus::CASTRATED, $this->policy->status(AnimalSex::MALE, 'NOVILLITO', false));
        $this->assertSame(CastrationStatus::CASTRATED, $this->policy->status(AnimalSex::MALE, 'novillo', false));
    }

    public function test_status_for_bull_categories_is_always_intact(): void
    {
        $this->assertSame(CastrationStatus::INTACT, $this->policy->status(AnimalSex::MALE, 'TORITO', false));
        $this->assertSame(CastrationStatus::INTACT, $this->policy->status(AnimalSex::MALE, 'TORO', false));
    }

    public function test_status_for_calf_depends_on_active_castration(): void
    {
        $this->assertSame(CastrationStatus::NOT_RECORDED, $this->policy->status(AnimalSex::MALE, 'TERNERO', false));
        $this->assertSame(CastrationStatus::CASTRATED, $this->policy->status(AnimalSex::MALE, 'TERNERO', true));
    }

    public function test_refusal_for_female(): void
    {
        $this->assertSame('sólo se castran machos', $this->policy->refusal(AnimalSex::FEMALE, 'TERNERO', null));
    }

    public function test_refusal_for_unassigned_category(): void
    {
        $this->assertSame('asigná la categoría primero', $this->policy->refusal(AnimalSex::MALE, null, null));
        $this->assertSame('asigná la categoría primero', $this->policy->refusal(AnimalSex::MALE, '', null));
    }

    public function test_refusal_for_steer_categories(): void
    {
        $this->assertSame('ya es castrado por su categoría', $this->policy->refusal(AnimalSex::MALE, 'NOVILLITO', null));
        $this->assertSame('ya es castrado por su categoría', $this->policy->refusal(AnimalSex::MALE, 'NOVILLO', null));
    }

    public function test_refusal_for_adult_bull(): void
    {
        $this->assertSame('un toro adulto no se castra desde aquí; si no sirve, se descarta', $this->policy->refusal(AnimalSex::MALE, 'TORO', null));
    }

    public function test_refusal_for_already_castrated(): void
    {
        $active = new CastrationRecord(1, '2026-10-12', CastrationMethod::BAND);
        $this->assertSame('ya está castrado desde 12/10/2026', $this->policy->refusal(AnimalSex::MALE, 'TERNERO', $active));
    }

    public function test_refusal_for_eligible_calf_and_torito(): void
    {
        $this->assertNull($this->policy->refusal(AnimalSex::MALE, 'TERNERO', null));
        $this->assertNull($this->policy->refusal(AnimalSex::MALE, 'TORITO', null));
    }

    public function test_allows_category_cs9_guard(): void
    {
        // Castrated cannot become TORITO or TORO
        $this->assertFalse($this->policy->allowsCategory(CastrationStatus::CASTRATED, 'TORITO'));
        $this->assertFalse($this->policy->allowsCategory(CastrationStatus::CASTRATED, 'TORO'));
        $this->assertFalse($this->policy->allowsCategory(CastrationStatus::CASTRATED, 'torito'));

        // Castrated can become other categories
        $this->assertTrue($this->policy->allowsCategory(CastrationStatus::CASTRATED, 'NOVILLITO'));
        $this->assertTrue($this->policy->allowsCategory(CastrationStatus::CASTRATED, 'NOVILLO'));
        $this->assertTrue($this->policy->allowsCategory(CastrationStatus::CASTRATED, 'TERNERO'));

        // Intact or unrecorded can become anything
        $this->assertTrue($this->policy->allowsCategory(CastrationStatus::INTACT, 'TORITO'));
        $this->assertTrue($this->policy->allowsCategory(CastrationStatus::NOT_RECORDED, 'TORITO'));
        $this->assertTrue($this->policy->allowsCategory(null, 'TORITO'));
    }

    public function test_targets_for(): void
    {
        $this->assertSame(['NOVILLITO', 'NOVILLO'], $this->policy->targetsFor('TORITO'));
        $this->assertSame([], $this->policy->targetsFor('TERNERO'));
        $this->assertSame([], $this->policy->targetsFor('TORO'));
    }
}

