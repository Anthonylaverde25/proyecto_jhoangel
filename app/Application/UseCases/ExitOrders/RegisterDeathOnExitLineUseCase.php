<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Application\DTOs\Caravans\RegisterDeathDTO;
use App\Application\UseCases\Caravans\RegisterDeathUseCase;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Exceptions\ExitOrderDomainException;
use App\Core\Exceptions\RegisterDeathException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IDeathCauseRepository;
use App\Core\Interfaces\IExitOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * A pending animal of an exit order died (01 §5.1): its line leaves the roll with the reason, and
 * with nothing holding it any more the death goes through "Registrar muerte" as always. One
 * transaction: a death that fails puts the line back.
 */
final class RegisterDeathOnExitLineUseCase
{
    public function __construct(
        private readonly IExitOrderRepository $orders,
        private readonly ExitOrderOfCompany $orderOfCompany,
        private readonly IDeathCauseRepository $causes,
        private readonly ICaravanRepository $caravans,
        private readonly RegisterDeathUseCase $registerDeath,
    ) {
    }

    public function __invoke(
        int $orderId,
        int $caravanId,
        string $deathDate,
        int $deathCauseId,
        ?string $observations,
        ?int $deathBatchId,
        int $companyId,
        ?int $userId,
    ): ExitOrderEntity {
        return DB::transaction(function () use ($orderId, $caravanId, $deathDate, $deathCauseId, $observations, $deathBatchId, $companyId, $userId): ExitOrderEntity {
            $order = ($this->orderOfCompany)($orderId, $companyId);
            $cause = $this->causes->findActiveById($deathCauseId)
                ?? throw ExitOrderDomainException::on('death_cause_id', 'La causa elegida no existe o está inactiva.');
            $label = $this->caravans->findById($caravanId)?->label() ?? "El animal {$caravanId}";

            $order->skipByDeath($caravanId, $deathDate, (string) $cause['name'], $label);
            $this->orders->save($order, $userId, "Baja por muerte: {$label}", ['caravan_id' => $caravanId, 'death_date' => $deathDate]);

            try {
                ($this->registerDeath)(new RegisterDeathDTO([$caravanId], $deathDate, $deathCauseId, $observations, $deathBatchId, $companyId, $userId));
            } catch (RegisterDeathException $e) {
                // The death screen keys its errors by caravan_ids.0; here there is one animal.
                $errors = $e->getErrors();
                throw ExitOrderDomainException::many(array_merge(...array_map(
                    fn (string $field, array $messages) => [str_starts_with($field, 'caravan_ids') ? 'death_date' : $field => $messages],
                    array_keys($errors),
                    $errors
                )));
            }

            return ($this->orderOfCompany)($orderId, $companyId);
        });
    }
}
