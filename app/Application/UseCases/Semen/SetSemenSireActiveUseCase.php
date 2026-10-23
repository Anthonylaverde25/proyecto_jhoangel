<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Core\Entities\SemenSireEntity;
use App\Core\Interfaces\ISemenSireRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Deactivating is refused while the sire has active semen items (SS3). */
final class SetSemenSireActiveUseCase
{
    public function __construct(
        private readonly ISemenSireRepository $sires,
    ) {
    }

    public function __invoke(int $id, bool $active, int $companyId): SemenSireEntity
    {
        $sire = $this->sires->findById($id, $companyId) ?? throw new ModelNotFoundException("Semen sire {$id} not found.");

        return $this->sires->save($sire->withActive($active, $this->sires->hasActiveItems($id, $companyId)));
    }
}
