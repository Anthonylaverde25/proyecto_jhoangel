<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\GestationEntity;
use App\Core\Entities\ServiceBatchDetailEntity;
use App\Core\Entities\ServiceBatchEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\ServiceBatchDomainException;
use App\Core\ValueObjects\CaravanNumber;
use PHPUnit\Framework\TestCase;

class ServiceBatchEntityTest extends TestCase
{
    public function test_admits_homogeneous_female_and_male(): void
    {
        $batch = new BatchEntity(id: 1, name: 'Lote Entore 2026', farmId: null, observaciones: null);
        $detail = new ServiceBatchDetailEntity(
            id: 1,
            batchId: 1,
            femaleCategoryId: 10,
            maleCategoryId: 20,
            femaleCategoryName: 'Vaquillona',
            maleCategoryName: 'Toro'
        );
        $serviceBatch = new ServiceBatchEntity($batch, $detail);

        $female = new CaravanEntity(
            id: 101,
            identification: new CaravanNumber('H-001'),
            teeth: 2,
            sex: AnimalSex::FEMALE,
            categoryId: 10,
            categoryName: 'Vaquillona'
        );

        $male = new CaravanEntity(
            id: 201,
            identification: new CaravanNumber('M-001'),
            teeth: 4,
            sex: AnimalSex::MALE,
            categoryId: 20,
            categoryName: 'Toro'
        );

        $serviceBatch->validateAnimalAdmission($female);
        $serviceBatch->validateAnimalAdmission($male);

        $this->assertEquals(2.5, ServiceBatchEntity::calculateBullRatio(40, 1));
    }

    public function test_rejects_inhomogeneous_female_category(): void
    {
        $this->expectException(ServiceBatchDomainException::class);

        $batch = new BatchEntity(id: 1, name: 'Lote Entore 2026', farmId: null, observaciones: null);
        $detail = new ServiceBatchDetailEntity(
            id: 1,
            batchId: 1,
            femaleCategoryId: 10,
            maleCategoryId: 20,
            femaleCategoryName: 'Vaquillona',
            maleCategoryName: 'Toro'
        );
        $serviceBatch = new ServiceBatchEntity($batch, $detail);

        $cow = new CaravanEntity(
            id: 102,
            identification: new CaravanNumber('H-COW'),
            teeth: 6,
            sex: AnimalSex::FEMALE,
            categoryId: 11, // Vaca instead of Vaquillona
            categoryName: 'Vaca'
        );

        $serviceBatch->validateAnimalAdmission($cow);
    }

    public function test_rejects_pregnant_female_admission(): void
    {
        $this->expectException(ServiceBatchDomainException::class);

        $batch = new BatchEntity(id: 1, name: 'Lote Entore 2026', farmId: null, observaciones: null);
        $detail = new ServiceBatchDetailEntity(
            id: 1,
            batchId: 1,
            femaleCategoryId: 10,
            maleCategoryId: 20,
            femaleCategoryName: 'Vaquillona',
            maleCategoryName: 'Toro'
        );
        $serviceBatch = new ServiceBatchEntity($batch, $detail);

        $gestation = new GestationEntity(
            id: 50,
            startDate: '2026-01-01',
            estimatedDueDate: '2026-10-10',
            isCurrent: true,
            success: null,
            lossReasonId: null,
            lossNotes: null,
            endDate: null,
            notes: null,
            gestationStage: \App\Core\Enums\GestationStage::HEAD,
            gestationMonths: 3.0
        );

        $pregnantFemale = new CaravanEntity(
            id: 103,
            identification: new CaravanNumber('H-PREGNANT'),
            teeth: 2,
            sex: AnimalSex::FEMALE,
            categoryId: 10,
            categoryName: 'Vaquillona',
            gestations: [$gestation]
        );

        $serviceBatch->validateAnimalAdmission($pregnantFemale);
    }
}
