<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

interface ICaravanRegistrationSubmissionRepository
{
    /**
     * @return array<int, array{identification: string, id: int}>|null what the submission registered, if it went through
     */
    public function findRegistered(int $companyId, string $submissionId): ?array;

    /**
     * Must run inside the transaction that registers the animals.
     *
     * @param array<int, array{identification: string, id: int}> $registered
     */
    public function record(int $companyId, string $submissionId, array $registered): void;
}
