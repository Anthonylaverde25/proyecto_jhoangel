<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Core\Entities\ExitOrderEntity;

/** The text of the SALE movement: which order, and to whom. */
final class ExitObservation
{
    public static function of(ExitOrderEntity $order, ?string $buyerName = null): string
    {
        $buyer = $buyerName ?? $order->getBuyerName();

        return "Egreso {$order->getCode()}" . ($buyer !== null ? ": venta a {$buyer}" : '');
    }
}
