<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\BatchEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IBatchRepository;

/**
 * The batches an entry order sends its animals to: each one has to be an own batch, active. Checked
 * when the destinations are declared and again at every reception, since a batch can be closed in
 * the days the troop takes to arrive.
 */
final class EntryOrderDestinationValidator
{
    public function __construct(private readonly IBatchRepository $batches)
    {
    }

    /**
     * At a reception every problem is DESTINATION_UNAVAILABLE: the destination was valid when it
     * was declared, and the order has to be redistributed.
     *
     * @param list<int> $batchIds
     * @return array<int, BatchEntity> the batches, by id
     *
     * @throws EntryOrderDomainException
     */
    public function assertUsable(array $batchIds, bool $atReception = false): array
    {
        $found = [];

        foreach (array_values(array_unique($batchIds)) as $batchId) {
            $found[$batchId] = $this->usable($batchId, $atReception);
        }

        return $found;
    }

    /**
     * @throws EntryOrderDomainException
     */
    private function usable(int $batchId, bool $atReception): BatchEntity
    {
        $batch = $this->batches->findById($batchId);
        $code = fn (string $code) => $atReception ? 'DESTINATION_UNAVAILABLE' : $code;
        $after = $atReception ? ' Cambiá la distribución de la orden y volvé a cargar la recepción.' : '';

        if ($batch === null) {
            throw EntryOrderDomainException::invalid('El lote de destino no existe.' . $after, $code('DESTINATION_NOT_FOUND'), 'destinations');
        }

        if ($batch->isExternal()) {
            throw EntryOrderDomainException::invalid(
                "El lote {$batch->getName()} es de un proveedor: el destino tiene que ser un lote propio." . $after,
                $code('DESTINATION_NOT_OWN'),
                'destinations'
            );
        }

        if (!$batch->isActive()) {
            throw EntryOrderDomainException::invalid(
                "El lote {$batch->getName()} no está activo." . ($after !== '' ? $after : ' Elegí otro destino.'),
                $code('DESTINATION_INACTIVE'),
                'destinations'
            );
        }

        return $batch;
    }
}
