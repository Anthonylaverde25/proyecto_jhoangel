<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Application\Services\BirthOrderRosterBuilder;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Interfaces\IBirthOrderRepository;

/**
 * "Emitir orden": draft → issued. The draft may be days old, so what it names is checked again —
 * the females are still pregnant and no other open birth order took them meanwhile.
 */
final class IssueBirthOrderUseCase
{
    public function __construct(
        private readonly IBirthOrderRepository $repository,
        private readonly BirthOrderRosterBuilder $roster
    ) {
    }

    /**
     * @throws BirthOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId): BirthOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw BirthOrderDomainException::notFound();
        $motherIds = array_map(fn ($line) => $line->getMotherCaravanId(), $order->getAnimals());

        $this->roster->assertPregnantFemales($motherIds, $companyId);
        $this->roster->assertNotCommitted($motherIds, $companyId, $order->getId());

        $order->issue();

        return $this->repository->save($order, $userId, 'Borrador emitido');
    }
}
