<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Application\DTOs\CreatePartnerDTO;
use App\Core\Entities\FarmEntity;
use App\Core\Entities\PartnerContactEntity;
use App\Core\Entities\PartnerEntity;
use App\Core\Interfaces\IPartnerRepository;
use App\Core\ValueObjects\Cuit;

/**
 * A new supplier, customer or both, with its roles, origin farms and, when given, its first
 * contact, which becomes the default (C7). All in one transaction.
 */
final class CreatePartnerUseCase
{
    public function __construct(
        private readonly IPartnerRepository $repository
    ) {
    }

    public function __invoke(CreatePartnerDTO $dto): PartnerEntity
    {
        $farms = array_map(function (array $farmData) use ($dto) {
            $renspa = trim((string) ($farmData['renspa'] ?? ''));

            return new FarmEntity(
                id: null,
                name: (string) $farmData['name'],
                renspa: $renspa !== '' ? $renspa : null,
                location: isset($farmData['location']) ? (string) $farmData['location'] : null,
                providerId: 0, // Set by the repository once the partner exists
                companyId: $dto->companyId
            );
        }, $dto->farms);

        $entity = new PartnerEntity(
            id: null,
            name: $dto->name,
            commercialName: $dto->commercialName,
            cuit: Cuit::requireValid($dto->cuit)->value(),
            location: $dto->location,
            roles: $dto->roles,
            isActive: true,
            createdAt: null,
            farms: $farms,
            companyId: $dto->companyId,
        );

        if ($dto->contact !== null) {
            $entity->addContact(new PartnerContactEntity(
                id: null,
                name: $dto->contact->name,
                position: $dto->contact->position,
                phone: $dto->contact->phone,
                email: $dto->contact->email,
                notes: $dto->contact->notes,
            ));
        }

        return $this->repository->save($entity);
    }
}
