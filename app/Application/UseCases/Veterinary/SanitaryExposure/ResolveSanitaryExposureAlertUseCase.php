<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary\SanitaryExposure;

use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ISanitaryExposureRepository;
use DateTimeImmutable;

/**
 * Closes an exposure alert. It never closes by itself, even with every female reviewed: a
 * person states what was done with them.
 */
final class ResolveSanitaryExposureAlertUseCase
{
    public function __construct(private readonly ISanitaryExposureRepository $repository)
    {
    }

    /**
     * @throws DomainException
     */
    public function __invoke(int $alertId, int $companyId, ?int $userId, string $resolution): void
    {
        $resolution = trim($resolution);
        if ($resolution === '') {
            throw new DomainException('Indique cómo se resolvió la novedad.');
        }

        $alert = $this->repository->find($alertId, $companyId);
        if ($alert === null) {
            throw new DomainException("La novedad sanitaria con ID {$alertId} no existe.");
        }
        if (!$alert->isOpen()) {
            throw new DomainException('La novedad sanitaria ya está resuelta.');
        }

        $this->repository->resolve($alertId, $userId, $resolution, new DateTimeImmutable());
    }
}
