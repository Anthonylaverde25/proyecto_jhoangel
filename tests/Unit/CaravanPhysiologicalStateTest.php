<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\CaravanEntity;
use App\Core\Entities\GestationEntity;
use App\Core\Entities\LineageEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\GestationStage;
use App\Core\Enums\PhysiologicalState;
use App\Core\ValueObjects\CaravanNumber;
use PHPUnit\Framework\TestCase;

class CaravanPhysiologicalStateTest extends TestCase
{
    private function createFemaleCaravan(string $id = 'COW-001'): CaravanEntity
    {
        return new CaravanEntity(
            id: 1,
            identification: new CaravanNumber($id),
            teeth: 6,
            entryWeight: 420.0,
            exitWeight: null,
            breedId: 1,
            breedName: 'Angus',
            sex: AnimalSex::FEMALE
        );
    }

    public function test_male_animal_returns_unknown_physiological_state(): void
    {
        $bull = new CaravanEntity(
            id: 2,
            identification: new CaravanNumber('BULL-001'),
            teeth: 4,
            entryWeight: 650.0,
            exitWeight: null,
            breedId: 1,
            breedName: 'Hereford',
            sex: AnimalSex::MALE
        );

        $this->assertEquals(PhysiologicalState::UNKNOWN, $bull->getPhysiologicalState());
        $this->assertNull($bull->isPregnant());
    }

    public function test_female_in_service_order_returns_in_service(): void
    {
        $cow = $this->createFemaleCaravan();
        $cow->setIsInService(true);

        $this->assertEquals(PhysiologicalState::IN_SERVICE, $cow->getPhysiologicalState());
        $this->assertEquals('En Servicio / Entore', $cow->getPhysiologicalState()->label());
    }

    public function test_pregnant_and_lactating_returns_pregnant_lactating(): void
    {
        $cow = $this->createFemaleCaravan();
        
        // Add active gestation
        $cow->startNewGestation('2026-01-01', GestationStage::HEAD, 3.0);

        // Add active nursing lineage
        $lineage = new LineageEntity(
            id: 1,
            caravanId: 10,
            motherId: 1,
            motherIdentification: 'COW-001',
            fatherId: null,
            fatherIdentification: null,
            gestationId: null,
            birthDate: '2026-01-01',
            isNursing: true
        );
        $cow->recordLineage($lineage);

        $this->assertTrue($cow->hasActiveGestation());
        $this->assertTrue($cow->isNursing());
        $this->assertEquals(PhysiologicalState::PREGNANT_LACTATING, $cow->getPhysiologicalState());
        $this->assertEquals('Preñada y Lactando', $cow->getPhysiologicalState()->label());
    }

    public function test_pregnant_and_dry_returns_pregnant_dry(): void
    {
        $cow = $this->createFemaleCaravan();
        
        // Add active gestation
        $cow->startNewGestation('2026-01-01', GestationStage::HEAD, 3.0);

        // Lineage with isNursing = false (weaned calf)
        $lineage = new LineageEntity(
            id: 1,
            caravanId: 10,
            motherId: 1,
            motherIdentification: 'COW-001',
            fatherId: null,
            fatherIdentification: null,
            gestationId: null,
            birthDate: '2025-06-01',
            isNursing: false
        );
        $cow->recordLineage($lineage);

        $this->assertTrue($cow->hasActiveGestation());
        $this->assertFalse($cow->isNursing());
        $this->assertEquals(PhysiologicalState::PREGNANT_DRY, $cow->getPhysiologicalState());
        $this->assertEquals('Preñada y Seca', $cow->getPhysiologicalState()->label());
    }

    public function test_empty_and_lactating_returns_empty_lactating(): void
    {
        $cow = $this->createFemaleCaravan();
        
        // No active gestation, but active nursing
        $lineage = new LineageEntity(
            id: 1,
            caravanId: 10,
            motherId: 1,
            motherIdentification: 'COW-001',
            fatherId: null,
            fatherIdentification: null,
            gestationId: null,
            birthDate: '2026-02-01',
            isNursing: true
        );
        $cow->recordLineage($lineage);

        $this->assertFalse($cow->hasActiveGestation());
        $this->assertTrue($cow->isNursing());
        $this->assertEquals(PhysiologicalState::EMPTY_LACTATING, $cow->getPhysiologicalState());
        $this->assertEquals('Vacía y Lactando', $cow->getPhysiologicalState()->label());
    }

    public function test_empty_and_dry_returns_empty_dry(): void
    {
        $cow = $this->createFemaleCaravan();

        $this->assertFalse($cow->hasActiveGestation());
        $this->assertFalse($cow->isNursing());
        $this->assertEquals(PhysiologicalState::EMPTY_DRY, $cow->getPhysiologicalState());
        $this->assertEquals('Vacía y Seca', $cow->getPhysiologicalState()->label());
    }
}
