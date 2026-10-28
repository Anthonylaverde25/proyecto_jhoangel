<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

final class PartnerUseCases
{
    public function __construct(
        public readonly ListPartnersUseCase $list,
        public readonly CreatePartnerUseCase $create,
        public readonly FindPartnerUseCase $find,
        public readonly UpdatePartnerRolesUseCase $updateRoles,
        public readonly UpdatePartnerUseCase $update,
        public readonly AddPartnerContactUseCase $addContact,
        public readonly UpdatePartnerContactUseCase $updateContact,
        public readonly SetDefaultPartnerContactUseCase $setDefaultContact,
        public readonly RemovePartnerContactUseCase $removeContact,
    ) {
    }
}
