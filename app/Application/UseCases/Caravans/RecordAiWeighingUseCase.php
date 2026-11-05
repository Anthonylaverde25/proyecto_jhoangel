<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\AiWeighingResultDTO;
use App\Application\DTOs\RecordAiWeighingDTO;
use App\Application\DTOs\RecordCaravanWeightDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\CaravanWeightEntity;
use App\Core\Enums\WeighingSource;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Exceptions\AiWeighingException;
use App\Core\Exceptions\AnimalDeathException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Core\Services\WeighingSanityPolicy;

/**
 * The write an AI client may do on the herd: one control weighing of one own caravan. A person
 * sees the warnings before saving on the screen; here they stop the write until the user accepts
 * them. The writing itself, and the batch recomputation, stay in RecordCaravanWeightUseCase.
 */
final class RecordAiWeighingUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravans,
        private readonly ICaravanWeightRepository $weights,
        private readonly WeighingSanityPolicy $sanity,
        private readonly RecordCaravanWeightUseCase $record,
    ) {
    }

    /**
     * @throws AiWeighingException a blocker, or warnings the user has not accepted
     * @throws AnimalDeathException a weighing dated after the animal died
     */
    public function __invoke(RecordAiWeighingDTO $dto): AiWeighingResultDTO
    {
        // The company scope of the model applies here: another company's caravan is not found.
        $caravan = $this->caravans->findById($dto->caravanId) ?? throw AiWeighingException::caravanNotFound();
        $this->assertCanBeWeighedOn($caravan, $dto->date);

        $weight = round($dto->weight, 2);
        $history = $this->weights->findByCaravanId($dto->caravanId);
        $sameDay = array_values(array_filter($history, static fn (CaravanWeightEntity $w): bool => self::day($w) === $dto->date));

        // A client that retries after a timeout must not add the weighing twice.
        foreach ($sameDay as $weighing) {
            if (round($weighing->getWeight(), 2) === $weight) {
                throw AiWeighingException::duplicate($caravan->label(), $weight, $dto->date);
            }
        }

        $previous = $this->lastBefore($history, $dto->date);
        $warnings = $this->sanity->warnings($weight, $dto->date, $previous);
        if ($sameDay !== []) {
            $warnings[] = $this->sanity->sameDayWarning($sameDay[0]);
        }

        if ($warnings !== [] && !$dto->acknowledgeWarnings) {
            throw AiWeighingException::unacknowledgedWarnings($warnings);
        }

        // A weighing dated before another one joins the history without becoming the current one.
        $becomesCurrent = !$this->weights->hasWeighingAfter($dto->caravanId, new \DateTimeImmutable($dto->date));

        ($this->record)(new RecordCaravanWeightDTO(
            caravanId: $dto->caravanId,
            weight: $weight,
            weighingDate: $dto->date,
            notes: $dto->notes,
            type: WeighingTypeCode::CONTROL,
            source: WeighingSource::AI,
        ));

        $dailyChange = $previous !== null ? WeighingSanityPolicy::dailyChange($weight, $dto->date, $previous) : null;

        return new AiWeighingResultDTO(
            caravanId: $dto->caravanId,
            identification: $caravan->label(),
            previousWeight: $previous?->getWeight(),
            previousDate: $previous !== null ? self::day($previous) : null,
            newWeight: $weight,
            deltaKg: $previous !== null ? round($weight - $previous->getWeight(), 2) : null,
            dailyChangeKg: $dailyChange !== null ? round($dailyChange, 2) : null,
            becomesCurrent: $becomesCurrent,
            weighingDate: $dto->date,
            warnings: $warnings,
        );
    }

    /** What no confirmation can accept, checked before any warning is shown to the user. */
    private function assertCanBeWeighedOn(CaravanEntity $caravan, string $date): void
    {
        if ($this->caravans->findIdsInExternalBatches([(int) $caravan->getId()]) !== []) {
            throw AiWeighingException::externalCaravan($caravan->label());
        }

        $death = $caravan->getDeath();
        if ($death !== null && $date > $death->date) {
            throw AnimalDeathException::weighedAfterDeath($death->date);
        }

        $exit = $caravan->getExit();
        if ($exit !== null && $exit->date !== '' && $date > $exit->date) {
            throw AiWeighingException::weighedAfterExit($caravan->label(), $exit->date);
        }
    }

    /** @param CaravanWeightEntity[] $history */
    private function lastBefore(array $history, string $date): ?CaravanWeightEntity
    {
        $earlier = array_filter($history, static fn (CaravanWeightEntity $w): bool => self::day($w) < $date);
        usort($earlier, static fn (CaravanWeightEntity $a, CaravanWeightEntity $b): int => [self::day($b), $b->getId()] <=> [self::day($a), $a->getId()]);

        return $earlier[0] ?? null;
    }

    private static function day(CaravanWeightEntity $weighing): string
    {
        return $weighing->getWeighingDate()->format('Y-m-d');
    }
}
