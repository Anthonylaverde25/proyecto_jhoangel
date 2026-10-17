<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary\SanitaryExposure;

use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ISanitaryExposureRepository;
use DateTimeImmutable;

/**
 * Marks exposed females as reviewed, usually right after their pregnancy check. Reviewing
 * changes nothing about the female: it only records that someone looked at her.
 */
final class ReviewExposedFemalesUseCase
{
    public function __construct(private readonly ISanitaryExposureRepository $repository)
    {
    }

    /**
     * @param int[] $femaleIds
     *
     * @throws DomainException
     */
    public function __invoke(int $alertId, int $companyId, array $femaleIds, ?int $userId, ?string $note): int
    {
        $alert = $this->repository->find($alertId, $companyId);
        if ($alert === null) {
            throw new DomainException("La novedad sanitaria con ID {$alertId} no existe.");
        }
        if (!$alert->isOpen()) {
            throw new DomainException('La novedad sanitaria ya está resuelta.');
        }

        return $this->repository->markReviewed($alertId, $femaleIds, $userId, $note, new DateTimeImmutable());
    }
}
