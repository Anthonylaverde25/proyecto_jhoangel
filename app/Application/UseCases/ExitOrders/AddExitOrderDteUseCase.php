<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Application\DTOs\ExitOrders\ExitDteDTO;
use App\Core\Entities\ExitOrderDteEntity;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Exceptions\ExitOrderDomainException;
use App\Core\Interfaces\IExitOrderRepository;

/**
 * A DTE for the trip (01 §6): optional and always recommended. Loaded in any status but cancelled,
 * also days after the order closed; a number is used by one exit only.
 */
final class AddExitOrderDteUseCase
{
    public function __construct(
        private readonly IExitOrderRepository $orders,
        private readonly ExitOrderOfCompany $orderOfCompany,
    ) {
    }

    public function __invoke(int $orderId, ExitDteDTO $dto, int $companyId, ?int $userId): ExitOrderEntity
    {
        $order = ($this->orderOfCompany)($orderId, $companyId);
        $this->add($order, $dto, $userId);

        return ($this->orderOfCompany)($orderId, $companyId);
    }

    /** For the loads that bring their DTE along; the caller holds the transaction. */
    public function add(ExitOrderEntity $order, ExitDteDTO $dto, ?int $userId): void
    {
        $order->assertAcceptsDte();

        if ($dto->headCount < 1) {
            throw ExitOrderDomainException::on('head_count', 'El DTE declara al menos una cabeza.');
        }
        if ($this->orders->dteNumberTaken($dto->dteNumber, $order->getCompanyId())) {
            throw ExitOrderDomainException::dteTaken($dto->dteNumber);
        }

        $this->orders->addDte($order, new ExitOrderDteEntity(null, $dto->dteNumber, $dto->dteDate, $dto->headCount, $userId, $dto->observations), $userId);
    }
}
