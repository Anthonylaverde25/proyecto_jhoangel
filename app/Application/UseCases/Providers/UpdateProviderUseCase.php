<?php

declare(strict_types=1);

namespace App\Application\UseCases\Providers;

use App\Application\DTOs\UpdateProviderDTO;
use App\Core\Entities\ProviderEntity;
use App\Core\Interfaces\IProviderRepository;
use App\Core\ValueObjects\Cuit;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Corrects a provider's identity and contact. The CUIT is validated only when it changes, and it
 * cannot change once the provider issued a fiscal document (voided ones included).
 */
final class UpdateProviderUseCase
{
    public function __construct(
        private readonly IProviderRepository $providers,
    ) {
    }

    public function __invoke(int $id, UpdateProviderDTO $dto, int $companyId): ProviderEntity
    {
        $provider = $this->providers->findById($id);
        if ($provider === null || $provider->getCompanyId() !== $companyId) {
            throw new ModelNotFoundException("Provider {$id} not found.");
        }

        if (Cuit::fromString($dto->cuit)->value() !== $provider->getCuit()) {
            $provider->changeCuit(Cuit::requireValid($dto->cuit)->value());
        }

        $provider->updateDetails($dto->name, $dto->commercialName, $dto->location, $dto->email, $dto->phone);
        $this->providers->updateDetails($provider);

        return $this->providers->findById($id);
    }
}
