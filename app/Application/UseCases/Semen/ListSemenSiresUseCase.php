<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Core\Entities\SemenSireEntity;
use App\Core\Interfaces\ISemenSireRepository;

final class ListSemenSiresUseCase
{
    public function __construct(
        private readonly ISemenSireRepository $sires,
    ) {
    }

    /** @return list<SemenSireEntity> */
    public function __invoke(int $companyId, ?string $search, bool $includeInactive): array
    {
        return $this->sires->findAll($companyId, $search === null ? null : trim($search), $includeInactive);
    }
}
