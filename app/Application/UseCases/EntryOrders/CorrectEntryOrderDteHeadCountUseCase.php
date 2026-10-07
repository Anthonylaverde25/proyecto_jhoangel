<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Entities\EntryOrderIncidentEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Corregir cabezas": the head of a DTE were loaded wrong. The history keeps what it said and
 * what it says now; an ING-03 sheet still out for the old head is reported as outdated, and so are
 * the open incidents the correction left without a difference — they stay open until someone
 * writes down how they were settled.
 */
final class CorrectEntryOrderDteHeadCountUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @return array{order: EntryOrderEntity, warnings: list<array{code: string, message: string}>}
     *
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, int $dteId, int $headCount, string $reason): array
    {
        return DB::transaction(function () use ($id, $companyId, $userId, $dteId, $headCount, $reason): array {
            $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
            $result = $order->correctDteHeadCount($dteId, $headCount, $reason, $userId);
            $dte = $order->findDte($dteId);

            $warnings = array_map(fn (EntryOrderIncidentEntity $i) => [
                'code' => $i->getType()->value,
                'message' => $i->getDetail() . ' Se registró una novedad para revisar con el proveedor.',
            ], $result['raised']);

            foreach ($result['no_longer_differ'] as $incident) {
                $warnings[] = [
                    'code' => 'INCIDENT_NO_LONGER_DIFFERS',
                    'message' => "La corrección deja sin diferencia la novedad \"{$incident->getType()->label()}\": resolvela con lo que se acordó.",
                ];
            }

            foreach ($order->getReceiptSheets() as $sheet) {
                if ($sheet->getDteId() === $dteId && $sheet->isOutdatedFor($dte)) {
                    $warnings[] = [
                        'code' => 'RECEIPT_SHEET_OUTDATED',
                        'message' => "La hoja {$sheet->label()} se emitió para {$sheet->getDteHeadCount()} cabezas; el DTE ahora declara {$dte->getHeadCount()}. Emití una hoja nueva si todavía no se usó.",
                    ];
                }
            }

            $saved = $this->repository->save($order, $userId, trim($reason), [
                'action' => 'dte_head_count_corrected',
                'dte_number' => $dte->getDteNumber(),
                'from' => $result['from'],
                'to' => $headCount,
            ]);

            return ['order' => $saved, 'warnings' => $warnings];
        });
    }
}
