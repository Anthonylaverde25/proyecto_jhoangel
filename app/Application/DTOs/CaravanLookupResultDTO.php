<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Core\Enums\CaravanLookupStatus;

final readonly class CaravanLookupResultDTO
{
    public function __construct(
        public string $identification,
        public CaravanLookupStatus $status,
        public ?int $caravanId = null,
        public ?string $batchName = null,
    ) {
    }

    /**
     * Another company's animal is reported by status only: its id and batch belong to them.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'identification' => $this->identification,
            'status' => $this->status->value,
        ];

        if ($this->status === CaravanLookupStatus::OWN_COMPANY) {
            $data['caravan_id'] = $this->caravanId;
            $data['batch_name'] = $this->batchName;
        }

        return $data;
    }
}
