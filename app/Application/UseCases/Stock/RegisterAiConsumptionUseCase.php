<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Application\DTOs\Stock\AiConsumptionDTO;
use App\Application\DTOs\Stock\AiConsumptionResultDTO;
use App\Application\DTOs\Stock\StockLineDTO;
use App\Application\DTOs\Stock\StockOperationDTO;
use App\Application\DTOs\Stock\StockTransferDTO;
use App\Application\Services\Stock\AiConsumptionPolicy;
use App\Application\Services\Stock\AiConsumptionToken;
use App\Application\Services\Stock\LedgerResult;
use App\Application\Services\Stock\ResolvedLine;
use App\Core\Enums\ItemDocumentSource;
use App\Core\Exceptions\AiConsumptionException;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IItemStockRepository;
use Illuminate\Support\Facades\DB;

/**
 * The consumption an AI client may record: one article to one batch, in the option the user confirmed.
 * The writing stays in the screen's use cases; here it only adds the confirmation and the origin. When
 * the user chose to complete the warehouse, the transfers and the consumption go in one transaction.
 */
final class RegisterAiConsumptionUseCase
{
    public function __construct(
        private readonly AiConsumptionPolicy $policy,
        private readonly AiConsumptionToken $tokens,
        private readonly RegisterConsumptionUseCase $consumption,
        private readonly RegisterStockTransferUseCase $transfer,
        private readonly IItemDocumentRepository $documents,
        private readonly IItemStockRepository $stocks,
    ) {
    }

    /** @throws AiConsumptionException the confirmation does not hold, a blocker, or warnings not accepted */
    public function __invoke(AiConsumptionDTO $dto, int $companyId): AiConsumptionResultDTO
    {
        $token = (string) $dto->confirmationToken;
        if (!$this->tokens->matches($token, $dto->facts($companyId, (int) $dto->warehouseId, (int) $dto->headsTreated, $dto->transfers))) {
            throw AiConsumptionException::invalidConfirmation();
        }

        // Before the rest: a retry of what was already recorded must not be refused because of what
        // changed since, nor because the confirmation has expired meanwhile.
        $existing = $this->documents->findByIdempotencyKey($token, $companyId);
        if ($existing !== null) {
            return new AiConsumptionResultDTO(new LedgerResult($existing, [], true), [], $this->stockAfter($dto->itemId, $companyId));
        }
        if ($this->tokens->isExpired($token)) {
            throw AiConsumptionException::expiredConfirmation();
        }

        $evaluation = $this->policy->evaluate($dto, $companyId);
        if ($evaluation->blockers !== []) {
            throw AiConsumptionException::blocked($evaluation->blockers);
        }
        if ($evaluation->warnings !== [] && !$dto->acknowledgeWarnings) {
            throw AiConsumptionException::unacknowledgedWarnings($evaluation->warnings);
        }

        $line = $evaluation->line;
        [$ledger, $transfers] = DB::transaction(function () use ($dto, $companyId, $token, $line) {
            $moved = [];
            foreach ($dto->transfers as $transfer) {
                $moved[] = $this->moveToConsumptionWarehouse($transfer, $dto, $line, $token, $companyId);
            }

            $notes = $this->withTransfers($dto->notes, $moved);
            $result = ($this->consumption)(new StockOperationDTO(
                $dto->occurredAt,
                $token,
                [new StockLineDTO($dto->itemId, $dto->inputQuantity, $dto->inputUnitId, $dto->inputPresentationId)],
                $dto->batchId,
                $dto->headsTreated,
                $notes,
                $dto->createdBy,
                $dto->warehouseId,
                source: ItemDocumentSource::AI->value,
            ), $companyId);

            foreach ($moved as $operation) {
                $this->documents->annotate((int) $operation['result']->document->getId(), $companyId, "Completa el consumo N.º {$result->document->getNumber()}.");
            }

            return [$result, $moved];
        });

        $incidents = array_merge($ledger->incidents, ...array_map(fn (array $t) => $t['result']->incidents, $transfers));

        return new AiConsumptionResultDTO(
            new LedgerResult($ledger->document, $incidents),
            array_map(fn (array $t) => [
                'number' => (int) $t['result']->document->getNumber(),
                'from' => (string) $t['result']->document->getWarehouseName(),
                'quantity' => $t['quantity'],
            ], $transfers),
            $this->stockAfter($dto->itemId, $companyId),
        );
    }

    /**
     * Quantities of a completion are in the item's unit, whatever unit the user said.
     *
     * @param array{from_warehouse_id: int, quantity: string} $transfer
     * @return array{result: LedgerResult, quantity: string}
     */
    private function moveToConsumptionWarehouse(array $transfer, AiConsumptionDTO $dto, ResolvedLine $line, string $token, int $companyId): array
    {
        $result = ($this->transfer)(new StockTransferDTO(
            $transfer['from_warehouse_id'],
            (int) $dto->warehouseId,
            $dto->occurredAt,
            substr(hash('sha256', "{$token}|{$transfer['from_warehouse_id']}"), 0, 64),
            [new StockLineDTO($dto->itemId, $transfer['quantity'], $line->item->getUnitOfMeasureId(), null)],
            null,
            $dto->createdBy,
            ItemDocumentSource::AI->value,
        ), $companyId);

        return ['result' => $result, 'quantity' => $transfer['quantity']];
    }

    /** @param list<array{result: LedgerResult, quantity: string}> $moved */
    private function withTransfers(?string $notes, array $moved): ?string
    {
        if ($moved === []) {
            return $notes;
        }
        $numbers = implode(', ', array_map(fn (array $t) => 'N.º ' . $t['result']->document->getNumber(), $moved));

        return trim(($notes === null ? '' : "{$notes} · ") . "Almacén completado con la transferencia {$numbers}.");
    }

    /** @return list<array{warehouse: string, quantity: string}> */
    private function stockAfter(int $itemId, int $companyId): array
    {
        $rows = [];
        foreach ($this->stocks->findByItem($itemId, $companyId) as $stock) {
            if ($stock->isWarehouseActive()) {
                $rows[] = ['warehouse' => (string) $stock->getWarehouseName(), 'quantity' => $stock->getQuantity()];
            }
        }

        return $rows;
    }
}
