<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\DTOs\UpsertCaravanResultDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanNumber;

final class UpsertCaravanUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    public function __invoke(RegisterCaravanDTO $dto): UpsertCaravanResultDTO
    {
        $identification = new CaravanNumber($dto->identification);
        $existingEntity = $this->caravanRepository->findByIdentification($identification);

        $category = $dto->category !== null ? AnimalCategory::from($dto->category) : null;

        if ($existingEntity !== null) {
            // Lógica de UPDATE (Upsert)
            $existingEntity->updateDetails(
                $category,
                (int) $dto->teeth,
                $dto->entryWeight !== null ? (float) $dto->entryWeight : null,
                null, 
                $dto->breed,
                $dto->sex
            );

            $this->caravanRepository->save($existingEntity);

            return new UpsertCaravanResultDTO('updated', $existingEntity->getId());
        }

        // Lógica de INSERT: Validar campo excluyente
        if ($dto->sex === null || trim($dto->sex) === '') {
            throw new \App\Core\Exceptions\DomainException("El campo 'sexo' es obligatorio para registrar una nueva caravana.");
        }

        $newEntity = new CaravanEntity(
            null,
            $identification,
            $category,
            (int) $dto->teeth,
            $dto->entryWeight !== null ? (float) $dto->entryWeight : null,
            null,
            $dto->breed,
            $dto->sex,
            null
        );

        $savedEntity = $this->caravanRepository->save($newEntity);

        return new UpsertCaravanResultDTO('created', $savedEntity->getId());
    }
}
