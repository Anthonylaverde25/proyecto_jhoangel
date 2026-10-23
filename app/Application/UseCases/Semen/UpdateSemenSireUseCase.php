<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Application\DTOs\Semen\SemenSireDTO;
use App\Core\Entities\SemenSireEntity;
use App\Core\Interfaces\ISemenSireRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Edits a sire; an own bull keeps its caravan, name and breed (SS2). */
final class UpdateSemenSireUseCase
{
    public function __construct(
        private readonly ISemenSireRepository $sires,
        private readonly SemenSireFactory $factory,
    ) {
    }

    public function __invoke(int $id, SemenSireDTO $dto, int $companyId): SemenSireEntity
    {
        $current = $this->sires->findById($id, $companyId) ?? throw new ModelNotFoundException("Semen sire {$id} not found.");

        return $this->sires->save($this->factory->build($dto, $companyId, $current));
    }
}
