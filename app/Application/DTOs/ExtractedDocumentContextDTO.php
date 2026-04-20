<?php

declare(strict_types=1);

namespace App\Application\DTOs;

readonly class ExtractedDocumentContextDTO
{
    public function __construct(
        public ?string $cuit,
        public ?string $renspa,
        public ?string $lote,
        public ?int $providerId = null,
        public ?int $farmId = null,
        public ?int $batchId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cuit' => $this->cuit,
            'renspa' => $this->renspa,
            'lote' => $this->lote,
            'provider_id' => $this->providerId,
            'farm_id' => $this->farmId,
            'batch_id' => $this->batchId,
        ];
    }
}
