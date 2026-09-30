<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\Services\OpenOrderCommitmentChecker;
use App\Core\Entities\BirthHistoryEntity;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICompanyContext;

final class ListBirthHistoryUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository,
        private readonly OpenOrderCommitmentChecker $commitments,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * Every calving with its calf, and — for the calves still at foot — the open order that already
     * holds them, so the births list does not offer them to a second one.
     *
     * @return BirthHistoryEntity[]
     */
    public function __invoke(): array
    {
        $history = $this->repository->findBirthHistory();
        $nursing = array_map(
            fn (BirthHistoryEntity $record) => $record->getCalfId(),
            array_filter($history, fn (BirthHistoryEntity $record) => $record->isNursing())
        );

        $companyId = (int) $this->companyContext->getCompanyId();
        $committed = $companyId > 0 ? $this->commitments->committed(array_values($nursing), $companyId) : [];

        foreach ($history as $record) {
            $record->holdByOpenOrder($committed[$record->getCalfId()] ?? null);
        }

        return $history;
    }
}
