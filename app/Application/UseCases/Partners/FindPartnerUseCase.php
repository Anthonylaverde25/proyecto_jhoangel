<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Core\Entities\PartnerEntity;
use App\Core\Interfaces\IPartnerRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class FindPartnerUseCase
{
    public function __construct(
        private readonly IPartnerRepository $repository
    ) {
    }

    public function __invoke(int $id): PartnerEntity
    {
        $partner = $this->repository->findById($id);

        if (!$partner) {
            throw new ModelNotFoundException("Partner with ID {$id} not found.");
        }

        return $partner;
    }
}
