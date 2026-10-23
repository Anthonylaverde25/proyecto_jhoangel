<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Interfaces\IServiceOrderInseminationRepository;

/**
 * Will the semen be enough? (semen S-B, P4, IA5). Per item: the straws in every tank, the ones the
 * open AI orders still expect (one per female with that semen planned and not inseminated yet) and
 * the ones of the order being prepared. Informative: nothing is reserved and nothing is blocked.
 */
final class GetSemenCoverageUseCase
{
    public function __construct(
        private readonly IServiceOrderInseminationRepository $inseminations,
    ) {
    }

    /**
     * @param list<int> $itemIds
     * @param array<int, int> $extra item id => straws of the order being prepared
     * @return list<array{item_id: int, balance: float, committed: int, extra: int, shortage: float}>
     */
    public function __invoke(array $itemIds, array $extra, int $companyId): array
    {
        $itemIds = array_values(array_unique(array_map('intval', array_merge($itemIds, array_keys($extra)))));
        $balances = $this->inseminations->tankBalances($itemIds, $companyId);
        $committed = $this->inseminations->committedStraws($itemIds, $companyId);

        return array_map(function (int $itemId) use ($balances, $committed, $extra): array {
            $balance = (float) ($balances[$itemId] ?? 0);
            $needed = ($committed[$itemId] ?? 0) + (int) ($extra[$itemId] ?? 0);

            return [
                'item_id' => $itemId,
                'balance' => $balance,
                'committed' => $committed[$itemId] ?? 0,
                'extra' => (int) ($extra[$itemId] ?? 0),
                'shortage' => max(0.0, $needed - $balance),
            ];
        }, $itemIds);
    }
}
