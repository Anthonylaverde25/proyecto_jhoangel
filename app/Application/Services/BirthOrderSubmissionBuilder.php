<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\BirthOrders\BirthFieldData;
use App\Application\DTOs\Par01\Par01SubmissionDTO;
use App\Core\Entities\BirthOrderEntity;

/**
 * Turns an order and what the screen declared for its females into the PAR-01 submission a sheet
 * would have produced, so executing from the screen and registering go through the one path that
 * registers calvings.
 *
 * Only the females the screen resolved become rows: a pending female left without an outcome is
 * simply not part of this round.
 */
final class BirthOrderSubmissionBuilder
{
    /**
     * @param array<int, BirthFieldData> $fieldDataByMotherId
     */
    public function fromOrder(
        BirthOrderEntity $order,
        string $origin,
        ?int $actionUserId,
        array $fieldDataByMotherId,
        ?string $roundDate = null
    ): Par01SubmissionDTO {
        $rows = [];

        foreach ($order->getAnimals() as $line) {
            $data = $fieldDataByMotherId[$line->getMotherCaravanId()] ?? null;

            if ($data === null || !$line->isPending()) {
                continue;
            }

            $rows[] = [
                'caravana_madre' => (string) $line->getMotherIdentification(),
                'resultado' => $data->outcome,
                'caravana_cria' => $data->calfIdentification,
                'sexo' => $data->calfSex,
                'peso' => $data->calfWeight,
                'raza' => null,
                'breed_id' => $data->calfBreedId,
                'dientes' => $data->calfTeeth,
                'father_id' => $data->fatherId,
                'fecha_nacimiento' => $data->birthDate,
                'observations' => $data->observations,
                // The screen only lists the order's own females.
                'fuera_de_orden' => false,
            ];
        }

        return new Par01SubmissionDTO(
            companyId: $order->getCompanyId(),
            rows: $rows,
            fechaRecorrida: $roundDate ?? (new \DateTimeImmutable('today'))->format('Y-m-d'),
            responsable: $order->getResponsable(),
            origin: $origin,
            birthOrderId: $order->getId(),
            actionUserId: $actionUserId,
            paperOrderCode: $order->getCode()
        );
    }
}
