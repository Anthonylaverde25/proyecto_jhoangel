<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Application\DTOs\RegisterCaravanDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanNumber;

final class RegisterCaravanUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * @throws DomainException
     */
    public function __invoke(RegisterCaravanDTO $dto): CaravanEntity
    {
        $identification = new CaravanNumber($dto->identification);

        // Validar si ya existe una caravana con ese identificador
        if ($this->caravanRepository->findByIdentification($identification) !== null) {
            throw new DomainException("Ya existe una caravana registrada con el número {$dto->identification}.");
        }

        $category = AnimalCategory::from($dto->category);

        $entity = new CaravanEntity(
            null,
            $identification,
            $category,
            $dto->teeth,
            $dto->entryWeight
        );

        return $this->caravanRepository->save($entity);
    }
}
