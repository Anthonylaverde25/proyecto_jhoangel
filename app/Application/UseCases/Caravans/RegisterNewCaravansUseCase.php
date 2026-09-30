<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\CaravanLookupResultDTO;
use App\Application\DTOs\RegisterCaravanDTO;
use App\Core\Enums\CaravanLookupStatus;
use App\Core\Exceptions\CaravansAlreadyRegisteredException;
use App\Core\Interfaces\ICaravanRegistrationSubmissionRepository;
use App\Core\Interfaces\ICompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Strict registration of new animals read at the chute: all or nothing.
 *
 * Unlike the bulk upsert, an existing tag is never updated nor transferred. A misread or
 * a repeated animal must surface as a conflict for the reviewer, not rewrite someone's data.
 *
 * Idempotent per submission: the phone may lose the reply and resend the same submission,
 * which then gets the original result instead of a conflict on its own tags.
 */
final class RegisterNewCaravansUseCase
{
    public function __construct(
        private readonly LookupCaravansUseCase $lookupCaravans,
        private readonly UpsertCaravanUseCase $upsertCaravan,
        private readonly ICaravanRegistrationSubmissionRepository $submissions,
        private readonly ICompanyContext $companyContext,
    ) {
    }

    /**
     * @param RegisterCaravanDTO[] $dtos
     * @return array<int, array{identification: string, id: int}>
     *
     * @throws CaravansAlreadyRegisteredException
     */
    public function __invoke(string $submissionId, array $dtos): array
    {
        $companyId = $this->companyContext->getCompanyId();

        $previous = $this->submissions->findRegistered($companyId, $submissionId);
        if ($previous !== null) {
            return $previous;
        }

        $identifications = array_map(static fn (RegisterCaravanDTO $dto): string => $dto->identification, $dtos);
        $this->assertNoneRegistered($identifications);

        try {
            return DB::transaction(function () use ($dtos, $companyId, $submissionId): array {
                $registered = [];

                foreach ($dtos as $dto) {
                    $result = $this->upsertCaravan->registerNewArrival($dto);
                    $registered[] = ['identification' => $dto->identification, 'id' => $result->id];
                }

                // Same transaction: the animals and the proof they were registered commit together.
                $this->submissions->record($companyId, $submissionId, $registered);

                return $registered;
            });
        } catch (UniqueConstraintViolationException) {
            // Either the same submission raced itself (a double tap), or someone registered one
            // of these tags between the check and the insert.
            $previous = $this->submissions->findRegistered($companyId, $submissionId);
            if ($previous !== null) {
                return $previous;
            }

            $this->assertNoneRegistered($identifications);

            throw CaravansAlreadyRegisteredException::forConflicts([]);
        }
    }

    /**
     * @param string[] $identifications
     *
     * @throws CaravansAlreadyRegisteredException
     */
    private function assertNoneRegistered(array $identifications): void
    {
        $conflicts = array_values(array_filter(
            ($this->lookupCaravans)($identifications),
            static fn (CaravanLookupResultDTO $r): bool => $r->status !== CaravanLookupStatus::NOT_FOUND
        ));

        if ($conflicts !== []) {
            throw CaravansAlreadyRegisteredException::forConflicts(array_map(
                static fn (CaravanLookupResultDTO $r): array => [
                    'identification' => $r->identification,
                    'status' => $r->status->value,
                ],
                $conflicts
            ));
        }
    }
}
