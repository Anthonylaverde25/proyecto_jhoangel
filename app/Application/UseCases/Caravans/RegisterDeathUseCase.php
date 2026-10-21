<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\Caravans\RegisterDeathDTO;
use App\Application\Services\CaravanBatchMover;
use App\Application\Services\DeathBatchResolver;
use App\Application\Services\OpenOrderCommitmentChecker;
use App\Core\Entities\CaravanEntity;
use App\Core\Exceptions\AnimalDeathException;
use App\Core\Exceptions\RegisterDeathException;
use App\Core\Interfaces\IBirthOrderRepository;
use App\Core\Interfaces\ICaravanDeathRepository;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IDeathCauseRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Registrar muerte": one or several animals, tagged or not, that died on the same day of the same
 * cause. The death is kept as a fact (caravan_deaths) and the animals go to a death batch with a
 * DEATH movement, which also takes a female out of a running service. Every animal is checked
 * before anything is written; one that fails leaves the whole load unwritten.
 */
final class RegisterDeathUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICaravanLineageRepository $lineageRepository,
        private readonly IDeathCauseRepository $causeRepository,
        private readonly ICaravanDeathRepository $deathRepository,
        private readonly IBirthOrderRepository $birthOrderRepository,
        private readonly OpenOrderCommitmentChecker $commitments,
        private readonly DeathBatchResolver $deathBatches,
        private readonly CaravanBatchMover $mover
    ) {
    }

    /**
     * @return list<CaravanEntity> the animals, now dead
     *
     * @throws RegisterDeathException
     */
    public function __invoke(RegisterDeathDTO $dto): array
    {
        return DB::transaction(function () use ($dto): array {
            $cause = $this->causeRepository->findActiveById($dto->deathCauseId)
                ?? throw RegisterDeathException::on('death_cause_id', 'La causa elegida no existe o está inactiva.');

            $caravans = $this->checked($dto);
            $deathBatch = $this->deathBatches->resolve($dto->deathBatchId);

            // The domain changes first (gestation closed, calf no longer at foot): saving the
            // entity after the move would write its old batch back.
            foreach ($caravans as $caravan) {
                $this->caravanRepository->save($caravan);

                if ($caravan->getLineage() !== null) {
                    $this->lineageRepository->save($caravan->getLineage());
                }
            }

            $observation = 'Muerte: ' . $cause['name'] . ($dto->observations !== null ? " · {$dto->observations}" : '');
            $movementIds = $this->mover->move($caravans, $deathBatch, new \DateTime($dto->deathDate), 'DEATH', $observation);

            foreach ($caravans as $caravanId => $caravan) {
                $this->deathRepository->save(
                    $dto->companyId,
                    $caravanId,
                    $dto->deathDate,
                    $cause['id'],
                    $dto->observations,
                    $caravan->getBatchId(),
                    (int) $deathBatch->getId(),
                    $movementIds[$caravanId] ?? null,
                    $dto->userId
                );
            }

            return array_values(array_filter(array_map(
                fn (int $id) => $this->caravanRepository->findById($id),
                array_keys($caravans)
            )));
        });
    }

    /**
     * Every animal of the load, with its death registered in the domain, or the errors of all of
     * them keyed by their position in the request.
     *
     * @return array<int, CaravanEntity> by id
     *
     * @throws RegisterDeathException
     */
    private function checked(RegisterDeathDTO $dto): array
    {
        $committed = $this->commitments->committed($dto->caravanIds, $dto->companyId);
        $openMothers = $this->birthOrderRepository->openMothers($dto->companyId);
        $serviceBulls = $this->deathRepository->activeServiceBulls($dto->caravanIds);
        $motherDeathReasonId = $this->birthOrderRepository->lossReasonIdByCode('MOTHER_DEATH', $dto->companyId);

        $errors = [];
        $caravans = [];

        foreach ($dto->caravanIds as $index => $caravanId) {
            $field = "caravan_ids.{$index}";
            $caravan = $this->caravanRepository->findById($caravanId);

            if ($caravan === null) {
                $errors[$field][] = 'El animal no existe.';
                continue;
            }

            $label = $caravan->label();
            $order = $committed[$caravanId] ?? $openMothers[$caravanId] ?? null;

            if ($order !== null) {
                $errors[$field][] = "{$label} está en la orden {$order}: resolvelo primero en esa orden.";
                continue;
            }

            if (isset($serviceBulls[$caravanId])) {
                $errors[$field][] = "{$label} trabaja en el servicio {$serviceBulls[$caravanId]}: registrá su muerte con «Reemplazar/retirar toro».";
                continue;
            }

            try {
                $caravan->registerDeath($dto->deathDate, $dto->deathCauseId, $dto->observations, $motherDeathReasonId);
            } catch (AnimalDeathException $e) {
                $errors[$field][] = $e->getMessage();
                continue;
            }

            $caravans[$caravanId] = $caravan;
        }

        if ($errors !== []) {
            throw RegisterDeathException::many($errors);
        }

        return $caravans;
    }
}
