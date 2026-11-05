<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\CaravanEntity;
use App\Core\Interfaces\IServiceOrderRepository;

/**
 * A load of caravans (form, bulk entry, imported sheet) never ends a pregnancy: "empty" is what those
 * screens send by default, so nobody may have decided it. A birth or a loss has its own flow. The
 * rest of the row is saved and the operator is told.
 */
final class CaravanLoadPregnancyGuard
{
    public function __construct(
        private readonly IServiceOrderRepository $orders
    ) {
    }

    /** What the load has to say back when it declared empty a female with a current pregnancy. */
    public function warningFor(CaravanEntity $caravan): string
    {
        $orderId = $caravan->getActiveGestation()?->getServiceOrderId();
        $companyId = $caravan->getCompanyId();
        $code = $orderId !== null && $companyId !== null ? $this->orders->findById($orderId, $companyId)?->getCode() : null;

        return $caravan->getIdentification()->getValue() . ' sigue preñada' . ($code !== null ? " (orden {$code})" : '')
            . ': esta carga no cierra la preñez. Si parió, registrá el parto; si la perdió, usá "Registrar aborto".';
    }
}
