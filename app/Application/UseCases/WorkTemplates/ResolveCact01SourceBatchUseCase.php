<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\DTOs\Cact01\Cact01SourceBatchResolutionDTO;
use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Core\Entities\AnimalCategoryEntity;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\AnimalCategoryTextResolver;

/**
 * Proposes the source batch of a scanned CACT-01 sheet from the animals it lists.
 *
 * The batch name at the top is handwritten, and a scan that reads "TEST CACT CRIO" for
 * "TEST CACT CRIA" matches nothing. The tags below it are a stronger witness: every animal
 * exists and sits in a batch, and the animals of a CACT-01 leave from its source batch, so the
 * batch holding most of them is the one the sheet came from.
 *
 * Read only. It answers the review screen, where somebody confirms it; the submission still
 * validates every animal against whatever batch was finally chosen.
 *
 * Having loaded the animals anyway, it also says what the system knows about each one — sex and
 * current category — so the screen can fill the cells the scan left blank.
 */
final class ResolveCact01SourceBatchUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly IAnimalCategoryRepository $categoryRepository,
    ) {
    }

    /**
     * @param string[] $identifications
     */
    public function execute(?string $writtenName, array $identifications): Cact01SourceBatchResolutionDTO
    {
        $tags = array_values(array_unique(array_filter(
            array_map(static fn ($raw): string => mb_strtoupper(trim((string) $raw)), $identifications),
            static fn (string $tag): bool => $tag !== ''
        )));

        /** @var array<int, BatchEntity> $activeBatches */
        $activeBatches = [];
        foreach ($this->batchRepository->findAll() as $batch) {
            if ($batch->isActive() && $batch->getId() !== null) {
                $activeBatches[$batch->getId()] = $batch;
            }
        }

        $nameMatch = $this->matchByName($writtenName, $activeBatches);

        // Where each animal read is today. An animal in an inactive batch cannot be leaving
        // from it (the submission rejects such a source), so it does not vote.
        $caravans = $tags === [] ? [] : $this->caravanRepository->findByIdentifications($tags);
        $votes = [];
        foreach ($caravans as $caravan) {
            $batchId = $caravan->getBatchId();
            if ($batchId !== null && isset($activeBatches[$batchId])) {
                $votes[$batchId] = ($votes[$batchId] ?? 0) + 1;
            }
        }
        arsort($votes);

        $voters = array_sum($votes);
        $leaderId = array_key_first($votes);
        // An absolute majority, not the largest share: a troop split evenly between two batches
        // is a sheet somebody has to look at, not one to settle by a coin toss.
        $majorityId = $leaderId !== null && $votes[$leaderId] * 2 > $voters ? $leaderId : null;

        $distribution = [];
        foreach (array_slice($votes, 0, 3, true) as $batchId => $count) {
            $distribution[] = ['id' => $batchId, 'name' => $activeBatches[$batchId]->getName(), 'count' => $count];
        }

        [$basis, $proposedId] = match (true) {
            $nameMatch !== null && $majorityId === $nameMatch->getId() => [Cact01SourceBatchResolutionDTO::BASIS_NAME_AND_CARAVANS, $majorityId],
            $nameMatch !== null && $majorityId === null => [Cact01SourceBatchResolutionDTO::BASIS_NAME, $nameMatch->getId()],
            $nameMatch !== null => [Cact01SourceBatchResolutionDTO::BASIS_CONFLICT, null],
            $majorityId !== null => [Cact01SourceBatchResolutionDTO::BASIS_CARAVANS, $majorityId],
            default => [Cact01SourceBatchResolutionDTO::BASIS_NONE, null],
        };

        return new Cact01SourceBatchResolutionDTO(
            basis: $basis,
            proposed: $proposedId !== null ? $this->summary($activeBatches[$proposedId]) : null,
            nameMatch: $nameMatch !== null ? $this->summary($nameMatch) : null,
            caravansMatch: $majorityId !== null ? $this->summary($activeBatches[$majorityId]) : null,
            caravansRead: count($tags),
            caravansFound: count($caravans),
            caravansInMatch: $majorityId !== null ? $votes[$majorityId] : 0,
            distribution: $distribution,
            animals: $this->describe($caravans),
        );
    }

    /**
     * @param array<string, CaravanEntity> $caravans keyed by upper-cased identification
     * @return list<array{identification: string, sex: string, category_label: ?string}>
     */
    private function describe(array $caravans): array
    {
        if ($caravans === []) {
            return [];
        }

        /** @var array<int, AnimalCategoryEntity> $categories */
        $categories = [];
        foreach ($this->categoryRepository->all() as $category) {
            $categories[(int) $category->getId()] = $category;
        }

        $animals = [];
        foreach ($caravans as $identification => $caravan) {
            $animals[] = [
                'identification' => (string) $identification,
                'sex' => $caravan->getSex()->value,
                'category_label' => $this->categoryLabel($caravan, $categories),
            ];
        }

        return $animals;
    }

    /**
     * The C/S label the sheet prints for the animal: "Vaquillona / Reposición".
     *
     * @param array<int, AnimalCategoryEntity> $categories
     */
    private function categoryLabel(CaravanEntity $caravan, array $categories): ?string
    {
        $category = $categories[(int) $caravan->getCategoryId()] ?? null;

        if ($category === null) {
            return $caravan->getCategoryName();
        }

        foreach ($category->getSubcategories() as $subcategory) {
            if ((int) $subcategory->getId() === $caravan->getSubcategoryId()) {
                return AnimalCategoryTextResolver::label($category, $subcategory);
            }
        }

        return AnimalCategoryTextResolver::label($category, null);
    }

    /**
     * @param array<int, BatchEntity> $batches
     */
    private function matchByName(?string $writtenName, array $batches): ?BatchEntity
    {
        $key = Cact01SubmissionDTO::normalizeKey($writtenName);

        if ($key === '') {
            return null;
        }

        foreach ($batches as $batch) {
            if (Cact01SubmissionDTO::normalizeKey($batch->getName()) === $key) {
                return $batch;
            }
        }

        return null;
    }

    /**
     * @return array{id: int, name: string}
     */
    private function summary(BatchEntity $batch): array
    {
        return ['id' => (int) $batch->getId(), 'name' => $batch->getName()];
    }
}
