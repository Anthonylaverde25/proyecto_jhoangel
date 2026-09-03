<?php

declare(strict_types=1);

namespace App\Application\DTOs\Ing01;

final class Ing01SubmissionDTO
{
    /**
     * @param int $companyId
     * @param string|null $batchName
     * @param string $entryDate
     * @param Ing01CaravanItemDTO[] $caravans
     * @param string|null $providerBatchName
     * @param string|null $providerName
     * @param string|null $providerFarmName
     * @param string|null $providerCuit
     * @param string|null $providerRenspa
     * @param string|null $guiaDte
     * @param string|null $activity
     */
    public function __construct(
        public readonly int $companyId,
        public readonly ?string $batchName,
        public readonly string $entryDate,
        public readonly array $caravans,
        public readonly ?string $providerBatchName = null,
        public readonly ?string $providerName = null,
        public readonly ?string $providerFarmName = null,
        public readonly ?string $providerCuit = null,
        public readonly ?string $providerRenspa = null,
        public readonly ?string $guiaDte = null,
        public readonly ?string $activity = null
    ) {
    }

    /**
     * Create DTO from an array payload.
     *
     * @param array<string, mixed> $data
     * @param int $companyId
     * @return self
     */
    public static function fromArray(array $data, int $companyId): self
    {
        $rawBatchName = $data['batch_name'] ?? $data['lote'] ?? null;
        $batchName = $rawBatchName !== null && trim((string)$rawBatchName) !== '' ? trim((string)$rawBatchName) : null;

        $rawProviderBatchName = $data['provider_batch_name'] ?? $data['lote_proveedor'] ?? $data['lote_origen'] ?? $data['lt_origen'] ?? null;
        $providerBatchName = $rawProviderBatchName !== null && trim((string)$rawProviderBatchName) !== '' ? trim((string)$rawProviderBatchName) : null;

        $rawProviderName = $data['provider_name'] ?? $data['proveedor'] ?? null;
        $providerName = $rawProviderName !== null && trim((string)$rawProviderName) !== '' ? trim((string)$rawProviderName) : null;

        $rawProviderFarmName = $data['provider_farm_name'] ?? $data['estab_origen'] ?? $data['establecimiento_origen'] ?? $data['farm_origen'] ?? null;
        $providerFarmName = $rawProviderFarmName !== null && trim((string)$rawProviderFarmName) !== '' ? trim((string)$rawProviderFarmName) : null;

        $entryDate = self::normalizeDate((string)($data['entry_date'] ?? $data['fecha'] ?? ''));

        $caravanList = [];
        $rawCaravans = $data['caravans'] ?? $data['rows'] ?? [];

        foreach ($rawCaravans as $row) {
            if (is_array($row)) {
                $item = Ing01CaravanItemDTO::fromArray($row);
                if ($item->identification !== '') {
                    $caravanList[] = $item;
                }
            }
        }

        $cuit = $data['provider_cuit'] ?? $data['cuit'] ?? null;
        $renspa = $data['provider_renspa'] ?? $data['renspa'] ?? $data['origin_renspa'] ?? null;
        $guiaDte = $data['guia_dte'] ?? $data['dte'] ?? $data['remito'] ?? null;

        $rawActivity = $data['activity'] ?? $data['actividad'] ?? $data['activity_code'] ?? $data['activity_name'] ?? null;
        $activity = $rawActivity !== null && trim((string)$rawActivity) !== '' ? trim((string)$rawActivity) : null;

        return new self(
            companyId: $companyId,
            batchName: $batchName,
            entryDate: $entryDate,
            caravans: $caravanList,
            providerBatchName: $providerBatchName,
            providerName: $providerName,
            providerFarmName: $providerFarmName,
            providerCuit: $cuit !== null && trim((string)$cuit) !== '' ? trim((string)$cuit) : null,
            providerRenspa: $renspa !== null && trim((string)$renspa) !== '' ? trim((string)$renspa) : null,
            guiaDte: $guiaDte !== null && trim((string)$guiaDte) !== '' ? trim((string)$guiaDte) : null,
            activity: $activity
        );
    }

    /**
     * Normalize date string to standard YYYY-MM-DD format.
     */
    public static function normalizeDate(?string $rawDate): string
    {
        if ($rawDate === null) {
            return date('Y-m-d');
        }

        $clean = trim($rawDate);
        if ($clean === '' || str_contains($clean, '____')) {
            return date('Y-m-d');
        }

        // Remove spaces inside date separators, e.g. "28 / 08 / 2026" -> "28/08/2026"
        $clean = preg_replace('/\s*([\/\-\.])\s*/', '$1', $clean) ?? $clean;
        $clean = trim($clean);

        // Pattern 1: YYYY-MM-DD
        if (preg_match('/^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})$/', $clean, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }

        // Pattern 2: DD-MM-YYYY
        if (preg_match('/^(\d{1,2})[-\/\.](\d{1,2})[-\/\.](\d{4})$/', $clean, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        $timestamp = strtotime($clean);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return date('Y-m-d');
    }
}
