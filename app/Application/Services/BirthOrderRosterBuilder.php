<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\BirthOrders\EmitBirthOrderDTO;
use App\Core\Entities\BirthOrderAnimalEntity;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Interfaces\IBirthOrderRepository;

/**
 * Turns the females the birth order screen chose into the roll of an order, and checks what an
 * ORDER needs: each one a female of the company with a pregnancy in course.
 *
 * Used by creating, by rewriting a draft, by issuing it and by registering, so all of them agree on
 * what a valid birth order is.
 */
final class BirthOrderRosterBuilder
{
    public function __construct(private readonly IBirthOrderRepository $repository)
    {
    }

    /**
     * @return BirthOrderAnimalEntity[]
     *
     * @throws BirthOrderDomainException
     */
    public function build(EmitBirthOrderDTO $dto): array
    {
        $facts = $this->assertPregnantFemales($dto->motherIds, $dto->companyId);

        if (count(array_unique($dto->motherIds)) !== count($dto->motherIds)) {
            throw BirthOrderDomainException::domainError('Una hembra figura dos veces en la orden.', 'DUPLICATED_ANIMAL');
        }

        return array_map(fn (int $id) => new BirthOrderAnimalEntity(
            id: null,
            motherCaravanId: $id,
            gestationId: $facts[$id]['gestation_id'],
            sourceBatchId: $facts[$id]['batch_id']
        ), $dto->motherIds);
    }

    /**
     * Every female exists in the company, is a female and is pregnant. Checked when the order is
     * written and again when a draft is issued: a draft may be days old.
     *
     * @param int[] $motherIds
     * @return array<int, array{identification: string, sex: string, batch_id: ?int, gestation_id: ?int}>
     *
     * @throws BirthOrderDomainException
     */
    public function assertPregnantFemales(array $motherIds, int $companyId): array
    {
        $motherIds = array_values(array_unique($motherIds));

        if ($motherIds === []) {
            throw BirthOrderDomainException::domainError('Elegí al menos una hembra preñada.', 'EMPTY_ROLL');
        }

        $facts = $this->repository->motherFacts($motherIds, $companyId);
        $missing = array_filter($motherIds, fn (int $id) => !isset($facts[$id]));

        if ($missing !== []) {
            throw BirthOrderDomainException::domainError(count($missing) . ' hembra(s) de la orden no existen en la empresa.', 'MOTHER_NOT_FOUND');
        }

        $males = array_filter($facts, fn (array $fact) => $fact['sex'] !== 'H');

        if ($males !== []) {
            throw BirthOrderDomainException::domainError(
                'No son hembras: ' . implode(', ', array_column($males, 'identification')) . '.',
                'NOT_A_FEMALE'
            );
        }

        $notPregnant = array_filter($facts, fn (array $fact) => $fact['gestation_id'] === null);

        if ($notPregnant !== []) {
            throw BirthOrderDomainException::domainError(
                'Sin preñez en curso: ' . implode(', ', array_column($notPregnant, 'identification'))
                    . '. Una orden de parición lista vientres preñados; un parto no previsto se registra en la recorrida.',
                'NO_ACTIVE_GESTATION'
            );
        }

        return $facts;
    }

    /**
     * An issued order holds its females against other BIRTH orders only: two calving rolls for the
     * same female would give two answers to what happened to her. Transfer and weaning orders are
     * not checked — the season lasts weeks, and the calf follows its mother wherever she is moved.
     *
     * @param int[] $motherIds
     *
     * @throws BirthOrderDomainException
     */
    public function assertNotCommitted(array $motherIds, int $companyId, ?int $exceptOrderId = null): void
    {
        $committed = $this->repository->findCommittedMothers($motherIds, $companyId, $exceptOrderId);

        if ($committed === []) {
            return;
        }

        $facts = $this->repository->motherFacts(array_keys($committed), $companyId);
        $byOrder = [];

        foreach ($committed as $motherId => $code) {
            $byOrder[$code][] = $facts[$motherId]['identification'] ?? (string) $motherId;
        }

        $parts = [];
        foreach ($byOrder as $code => $tags) {
            $parts[] = implode(', ', $tags) . " (orden {$code})";
        }

        throw BirthOrderDomainException::domainError(
            'Ya están en otra orden de parición abierta: ' . implode('; ', $parts) . '. Ejecutala, cerrala o anulala primero.',
            'ANIMAL_IN_OPEN_ORDER'
        );
    }
}
