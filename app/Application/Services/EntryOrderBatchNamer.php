<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\BatchEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IBatchRepository;

/**
 * The name of the batch an entry order creates. When the purchase comes from an auction the name
 * can be composed by the server ("338-12": auction termination and order number); otherwise the
 * user writes it. Either way the user decides.
 *
 * A name already used by an active batch of the same establishment is refused: it would be the
 * same batch written twice. A name used elsewhere is only advised against.
 */
final class EntryOrderBatchNamer
{
    public function __construct(private readonly IBatchRepository $batches)
    {
    }

    public static function compose(string $auctionNumber, int $orderNumber): string
    {
        return trim($auctionNumber) . '-' . $orderNumber;
    }

    /**
     * @return list<array{code: string, message: string}> warnings
     *
     * @throws EntryOrderDomainException
     */
    public function check(string $name, int $farmId): array
    {
        $warnings = [];

        foreach ($this->batches->findAllActiveByName($name) as $batch) {
            if ($batch->getFarmId() === $farmId) {
                throw EntryOrderDomainException::invalid(
                    "Ya existe un lote activo llamado '{$name}' en este establecimiento. Elegí otro nombre.",
                    'BATCH_NAME_IN_USE',
                    'batch_name'
                );
            }

            $warnings[] = [
                'code' => 'BATCH_NAME_SHARED',
                'message' => "El lote '{$name}' se llama igual que un lote de {$this->where($batch)} que ya existe. "
                    . 'Conviene darle otro nombre para distinguirlos.',
            ];
        }

        return $warnings;
    }

    private function where(BatchEntity $batch): string
    {
        return $batch->getFarmName() ?? mb_strtolower($batch->getBatchTypeName() ?? 'otro tipo');
    }
}
