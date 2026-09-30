<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Application\DTOs\Dest01\Dest01SubmissionDTO;
use App\Application\DTOs\WeaningOrders\WeaningFieldData;
use App\Application\Services\WeaningOrderSubmissionBuilder;
use App\Application\UseCases\WorkTemplates\ProcessDest01SubmissionUseCase;
use App\Core\Exceptions\Dest01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderRepository;

/**
 * "Ejecutar orden" from the screen: weans the order's pending calves.
 *
 * The submission is built from the ORDER, and goes through the DEST-01 processing like a scanned
 * sheet would — the one place that weans calves — marked as coming from the screen. The day it
 * happened is declared (today by default); the weights, notes and, when the order left it for the
 * chute, the category of each calf travel as field data.
 */
final class ExecuteWeaningOrderUseCase
{
    public function __construct(
        private readonly IWeaningOrderRepository $repository,
        private readonly ProcessDest01SubmissionUseCase $processDest01,
        private readonly WeaningOrderSubmissionBuilder $submissions
    ) {
    }

    /**
     * @param array<int, WeaningFieldData> $fieldDataByCaravanId
     * @return array<string, mixed> the DEST-01 result
     *
     * @throws Dest01ValidationException
     * @throws DomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, ?string $weaningDate = null, array $fieldDataByCaravanId = []): array
    {
        $order = $this->repository->findById($id, $companyId) ?? throw WeaningOrderDomainException::notFound();

        if (!$order->getStatus()->isOpen()) {
            throw WeaningOrderDomainException::domainError(
                $order->getStatus()->isEditable()
                    ? "La orden {$order->getCode()} es un borrador: emitila antes de ejecutarla."
                    : "La orden {$order->getCode()} está {$order->getStatus()->label()}: no admite más destetes.",
                'WEANING_ORDER_NOT_EXECUTABLE'
            );
        }

        $date = $weaningDate !== null
            ? substr($weaningDate, 0, 10)
            : (new \DateTimeImmutable('today'))->format('Y-m-d');

        return ($this->processDest01)(
            $this->submissions->fromOrder($order, $date, Dest01SubmissionDTO::ORIGIN_SCREEN, $userId, $fieldDataByCaravanId)
        );
    }
}
