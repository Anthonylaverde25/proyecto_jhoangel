<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class RegisterGestationDiagnosisDTO
{
    public function __construct(
        public int $caravanId,
        public int $serviceOrderId,
        public bool $isPregnant,
        public ?string $gestationStage = null,
        public ?float $gestationMonths = null,
        public ?int $confirmedSireId = null,
        /** Day of the diagnosis round; today when not given. */
        public ?string $diagnosisDate = null,
        public ?int $emptyDestinationBatchId = null,
        public ?int $userId = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            caravanId: (int) ($data['caravan_id'] ?? 0),
            serviceOrderId: (int) ($data['service_order_id'] ?? 0),
            isPregnant: filter_var($data['is_pregnant'] ?? false, FILTER_VALIDATE_BOOLEAN),
            gestationStage: isset($data['gestation_stage']) && $data['gestation_stage'] !== '' ? (string) $data['gestation_stage'] : null,
            gestationMonths: isset($data['gestation_months']) && $data['gestation_months'] !== '' ? (float) $data['gestation_months'] : null,
            confirmedSireId: isset($data['confirmed_sire_id']) && $data['confirmed_sire_id'] !== '' ? (int) $data['confirmed_sire_id'] : null,
            diagnosisDate: isset($data['diagnosis_date']) ? (string) $data['diagnosis_date'] : null,
            emptyDestinationBatchId: isset($data['empty_destination_batch_id']) && $data['empty_destination_batch_id'] !== ''
                ? (int) $data['empty_destination_batch_id']
                : null,
            userId: isset($data['user_id']) ? (int) $data['user_id'] : null
        );
    }
}
