<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\NotInseminatedReason;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderInseminationRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Terminar servicio" (semen S-B, menu only): ends an AI service without repaso bulls so its batch
 * is free before the diagnosis (APPROVED -> SERVICE_ENDED). Nothing has to be declared: a female
 * without insemination is not inseminated (IA12); the reasons are optional notes.
 */
final class EndInseminationServiceUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly IServiceOrderInseminationRepository $inseminations,
    ) {
    }

    /**
     * @param array<int, ?NotInseminatedReason> $reasons female_caravan_id => reason
     *
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $orderId, int $companyId, int $userId, string $endDate, array $reasons = [], ?string $observations = null): ServiceOrderEntity
    {
        $order = $this->orders->findById($orderId, $companyId)
            ?? throw ServiceOrderDomainException::domainError('La orden de servicio no existe.');
        if (substr($endDate, 0, 10) > now()->toDateString()) {
            throw ServiceOrderDomainException::domainError('La fecha de fin no puede ser futura.');
        }

        $order->endService($endDate, $observations);

        return DB::transaction(function () use ($order, $userId, $reasons): ServiceOrderEntity {
            $this->inseminations->setNotInseminatedReasons((int) $order->getId(), self::notInseminatedOnly($order, $reasons));
            $summary = $order->getDiagnosisSummary();

            return $this->orders->save(
                $order,
                $userId,
                "Servicio de IA terminado: {$summary->getTotal()} inseminadas, {$summary->getNotInseminated()} sin inseminar."
            );
        });
    }

    /**
     * Reasons only for females with no live insemination.
     *
     * @param array<int, ?NotInseminatedReason> $reasons
     * @return array<int, ?string>
     */
    public static function notInseminatedOnly(ServiceOrderEntity $order, array $reasons): array
    {
        $inseminated = [];
        foreach ($order->getFemaleExposures() as $female) {
            if (($female['last_insemination_on'] ?? null) !== null) {
                $inseminated[(int) $female['female_caravan_id']] = true;
            }
        }

        $out = [];
        foreach ($reasons as $femaleId => $reason) {
            if (!isset($inseminated[(int) $femaleId]) && in_array((int) $femaleId, $order->getFemaleCaravanIds(), true)) {
                $out[(int) $femaleId] = $reason?->value;
            }
        }

        return $out;
    }
}
