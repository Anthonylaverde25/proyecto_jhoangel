<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\Caravans\UpdateBreedingEligibilityDTO;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBreedingEligibilityRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\ValueObjects\BreedingEligibility;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The owner marks or unmarks animals as fit for breeding, one or many at a time.
 *
 * The decision governs the next entry into service only: an animal already working in an
 * active order stays there, and the response says so, so the user can take it out physically
 * (replace / retire the bull, transfer the cow).
 */
final class UpdateBreedingEligibilityUseCase
{
    public function __construct(
        private readonly IBreedingEligibilityRepository $repository,
        private readonly IServiceOrderRepository $serviceOrderRepository
    ) {
    }

    /**
     * @return array{updated: int, warnings: list<array{caravan_id: int, caravana: string, message: string}>}
     *
     * @throws DomainException
     */
    public function __invoke(UpdateBreedingEligibilityDTO $dto): array
    {
        $animals = $this->repository->findAnimals($dto->caravanIds, $dto->companyId);

        $missing = array_diff($dto->caravanIds, array_keys($animals));
        if ($missing !== []) {
            throw new DomainException('Caravanas inexistentes o de otro establecimiento (IDs: ' . implode(', ', $missing) . ').');
        }

        // Eligible animals carry no reason; an excluded one may carry one, which must fit its sex.
        $reason = $dto->isEligible ? null : $dto->reason;
        $note = $dto->isEligible ? null : $dto->note;

        if ($reason !== null) {
            if ($reason->requiresNote() && $note === null) {
                throw new DomainException("El motivo \"{$reason->label()}\" requiere una nota.");
            }

            $mismatched = [];
            foreach ($animals as $animal) {
                if ($animal['sex'] === null || !$reason->appliesTo($animal['sex'])) {
                    $mismatched[] = $animal['identification'];
                }
            }
            if ($mismatched !== []) {
                throw new DomainException(
                    "El motivo \"{$reason->label()}\" no corresponde al sexo de: " . implode(', ', $mismatched) . '.'
                );
            }
        }

        DB::transaction(fn () => $this->repository->apply(
            $dto->caravanIds,
            $dto->companyId,
            new BreedingEligibility($dto->isEligible, $reason),
            $note,
            $dto->userId,
            new DateTimeImmutable()
        ));

        $warnings = [];
        if (!$dto->isEligible) {
            $inService = $this->serviceOrderRepository->findActiveOrdersByCaravans($dto->caravanIds, $dto->companyId);
            foreach ($inService as $caravanId) {
                $tag = $animals[(int) $caravanId]['identification'];
                $warnings[] = [
                    'caravan_id' => (int) $caravanId,
                    'caravana' => $tag,
                    'message' => "{$tag} está en un servicio activo: la exclusión rige desde el próximo entore. Retírelo de la orden si no debe seguir.",
                ];
            }
        }

        return ['updated' => count($dto->caravanIds), 'warnings' => $warnings];
    }
}
