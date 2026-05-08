<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\UserEntity;
use App\Models\User;

class UserMapper
{
    public static function toEntity(User $model): UserEntity
    {
        $entity = new UserEntity(
            id: $model->id,
            name: $model->name,
            email: $model->email,
            role: 'admin' // Logic for roles can be added here
        );

        if ($model->relationLoaded('companies')) {
            foreach ($model->companies as $company) {
                $entity->addCompany(CompanyMapper::toEntity($company));
            }
        }

        return $entity;
    }

    public static function toResponseArray(UserEntity $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'role' => $user->getRole(),
            'data' => [
                'displayName' => $user->getName(),
                'photoURL' => 'assets/images/avatars/brian-hughes.jpg',
                'email' => $user->getEmail(),
            ]
        ];
    }
}
