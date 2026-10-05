<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Application\DTOs\BirthOrders\BirthFieldData;
use App\Application\DTOs\Par01\Par01SubmissionDTO;
use App\Application\Services\BirthOrderSubmissionBuilder;
use App\Application\UseCases\WorkTemplates\ProcessPar01SubmissionUseCase;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\Par01ValidationException;
use App\Core\Interfaces\IBirthOrderRepository;

/**
 * "Ejecutar orden" from the screen: registers what one round found for the order's pending females.
 *
 * The submission is built from the ORDER and goes through the PAR-01 processing like a scanned
 * sheet would — the one place that registers calvings — marked as coming from the screen. Females
 * sent without an outcome stay pending; one sent only with "no parió en fecha" becomes overdue.
 */
final class ExecuteBirthOrderUseCase
{
    public function __construct(
        private readonly IBirthOrderRepository $repository,
        private readonly ProcessPar01SubmissionUseCase $processPar01,
        private readonly BirthOrderSubmissionBuilder $submissions
    ) {
    }

    /**
     * @param array<int, BirthFieldData> $fieldDataByMotherId
     * @return array<string, mixed> the PAR-01 result
     *
     * @throws Par01ValidationException
     * @throws DomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, array $fieldDataByMotherId, ?string $roundDate = null): array
    {
        $order = $this->repository->findById($id, $companyId) ?? throw BirthOrderDomainException::notFound();

        if (!$order->getStatus()->isOpen()) {
            throw BirthOrderDomainException::domainError(
                $order->getStatus()->isEditable()
                    ? "La orden {$order->getCode()} es un borrador: emitila antes de ejecutarla."
                    : "La orden {$order->getCode()} está {$order->getStatus()->label()}: no admite más partos.",
                'BIRTH_ORDER_NOT_EXECUTABLE'
            );
        }

        $resolved = array_filter($fieldDataByMotherId, fn (BirthFieldData $data) => $data->declaresSomething());

        if ($resolved === []) {
            throw BirthOrderDomainException::domainError('Indicá el resultado de al menos un vientre, o marcá que no parió en fecha.', 'NOTHING_RESOLVED');
        }

        return ($this->processPar01)(
            $this->submissions->fromOrder($order, Par01SubmissionDTO::ORIGIN_SCREEN, $userId, $resolved, $roundDate)
        );
    }
}
