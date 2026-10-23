<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Application\DTOs\Semen\SemenSireDTO;
use App\Core\Entities\SemenSireEntity;
use App\Core\Interfaces\ISemenSireRepository;

/** A new sire, external or own bull, usually from the semen entry's "Nuevo padre" (plan 3.6). */
final class CreateSemenSireUseCase
{
    public function __construct(
        private readonly ISemenSireRepository $sires,
        private readonly SemenSireFactory $factory,
    ) {
    }

    public function __invoke(SemenSireDTO $dto, int $companyId): SemenSireEntity
    {
        return $this->sires->save($this->factory->build($dto, $companyId));
    }
}
