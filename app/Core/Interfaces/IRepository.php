<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

interface IRepository
{
    /**
     * @param int|string $id
     * @return object|null
     */
    public function find($id): ?object;

    /**
     * @param array $criteria
     * @return array
     */
    public function findBy(array $criteria): array;

    /**
     * @return array
     */
    public function findAll(): array;
}
