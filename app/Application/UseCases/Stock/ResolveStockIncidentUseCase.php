<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Entities\StockIncidentEntity;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Interfaces\IStockIncidentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A count discrepancy is explained by hand (N2). A negative balance or a critical stock resolve
 * themselves when the balance recovers: closing them by hand would only hide them until the next
 * movement.
 */
final class ResolveStockIncidentUseCase
{
    public function __construct(
        private readonly IStockIncidentRepository $incidents
    ) {
    }

    public function __invoke(int $id, string $resolution, int $companyId, ?int $userId): StockIncidentEntity
    {
        $incident = $this->incidents->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Stock incident {$id} not found.");
        if ($incident->getType()->resolvesByIdentification()) {
            throw ItemDocumentException::on('domain', 'Se resuelve identificando la partida de las pajuelas.');
        }
        if ($incident->getType()->resolvesItself()) {
            throw ItemDocumentException::on('domain', "{$incident->getType()->label()} se resuelve sola cuando el saldo se recupera.");
        }

        $incident->resolve($resolution, $userId, now()->toIso8601String());

        return $this->incidents->save($incident);
    }
}
