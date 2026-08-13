<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\AssignSireDTO;
use App\Core\Entities\LineageEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\SireIdentificationMethod;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Interfaces\ICaravanRepository;
use Illuminate\Support\Facades\DB;

final class AssignSireUseCase
{
    public function __construct(
        private readonly ICaravanLineageRepository $lineageRepository,
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    public function __invoke(AssignSireDTO $dto): LineageEntity
    {
        return DB::transaction(function () use ($dto) {
            // 1. Validate calf exists
            $calf = $this->caravanRepository->findById($dto->calfId);
            if ($calf === null) {
                throw new DomainException("Calf with ID {$dto->calfId} not found.");
            }

            // 2. Validate sire exists and is male
            $sire = $this->caravanRepository->findById($dto->fatherId);
            if ($sire === null) {
                throw new DomainException("Sire with ID {$dto->fatherId} not found.");
            }
            if ($sire->getSex() !== AnimalSex::MALE) {
                throw new DomainException("The specified animal (ID {$dto->fatherId}) is not male and cannot be assigned as sire.");
            }

            // 3. Validate identification method
            $validMethods = array_column(SireIdentificationMethod::cases(), 'value');
            if (!in_array($dto->identificationMethod, $validMethods, true)) {
                throw new DomainException("Invalid sire identification method: '{$dto->identificationMethod}'.");
            }

            // 4. Delegate to repository (validates no double-assignment internally)
            return $this->lineageRepository->assignSire(
                $dto->calfId,
                $dto->fatherId,
                $dto->identificationMethod,
                $dto->sireNotes
            );
        });
    }
}
