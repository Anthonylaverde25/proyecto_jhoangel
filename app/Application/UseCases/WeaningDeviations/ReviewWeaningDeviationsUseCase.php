<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningDeviations;

use App\Core\Entities\WeaningOrderDeviationEntity;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderDeviationRepository;
use App\Core\Interfaces\IWeaningOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Revisar": somebody saw these deviations and says why they are fine. It changes neither the
 * orders nor the calves; it leaves the note, who and when, and a line in each order's history.
 * A deviation already reviewed keeps its first review.
 */
final class ReviewWeaningDeviationsUseCase
{
    public function __construct(
        private readonly IWeaningOrderDeviationRepository $deviations,
        private readonly IWeaningOrderRepository $orders
    ) {
    }

    /**
     * @param list<int> $ids
     * @return WeaningOrderDeviationEntity[] the deviations, reviewed
     *
     * @throws WeaningOrderDomainException
     */
    public function __invoke(array $ids, int $companyId, ?int $userId, string $note): array
    {
        $ids = array_values(array_unique($ids));
        $found = $this->deviations->findByIds($ids, $companyId);

        if (count($found) !== count($ids)) {
            throw WeaningOrderDomainException::domainError('Algunos desvíos no existen o son de otra empresa.', 'DEVIATION_NOT_FOUND');
        }

        $toReview = array_values(array_filter($found, fn (WeaningOrderDeviationEntity $d) => !$d->isReviewed()));

        foreach ($toReview as $deviation) {
            $deviation->review($note, $userId);
        }

        if ($toReview === []) {
            return $found;
        }

        DB::transaction(function () use ($toReview, $companyId, $userId, $note): void {
            $this->deviations->saveReviews($toReview);

            $byOrder = [];
            foreach ($toReview as $deviation) {
                $byOrder[$deviation->getWeaningOrderId()][] = [
                    'identification' => $deviation->getIdentification(),
                    'kind' => $deviation->getKind()->value,
                    'kind_label' => $deviation->getKind()->label(),
                    'planned' => $deviation->getPlanned(),
                    'actual' => $deviation->getActual(),
                ];
            }

            foreach ($byOrder as $orderId => $items) {
                $order = $this->orders->findById((int) $orderId, $companyId);

                if ($order !== null) {
                    $this->orders->save($order, $userId, trim($note), ['kind' => 'DEVIATION_REVIEWED', 'items' => $items]);
                }
            }
        });

        return $this->deviations->findByIds($ids, $companyId);
    }
}
