<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Application\Services\Stock\LedgerResult;
use App\Application\Services\Stock\StockLedger;
use App\Core\Enums\ItemDocumentType;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Interfaces\IItemDocumentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Reverses an initial stock, a consumption or a count (DS11). A purchase receipt or a return is
 * reversed by voiding its provider document (E4, DS20). A transfer is reversed as a whole, from either
 * of its halves (DD10).
 */
final class ReverseItemDocumentUseCase
{
    public function __construct(
        private readonly IItemDocumentRepository $documents,
        private readonly StockLedger $ledger,
    ) {
    }

    public function __invoke(int $id, string $reason, string $idempotencyKey, int $companyId, ?int $userId): LedgerResult
    {
        $existing = $this->documents->findByIdempotencyKey($idempotencyKey, $companyId);
        if ($existing !== null) {
            return new LedgerResult($existing, [], true);
        }

        $original = $this->documents->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Item document {$id} not found.");
        $refusal = $original->reversalRefusal();
        if ($refusal !== null) {
            throw ItemDocumentException::on('domain', $refusal);
        }
        if (trim($reason) === '') {
            throw ItemDocumentException::on('reason', 'Indicá el motivo de la anulación.');
        }

        if ($original->getType()->isTransfer()) {
            $pair = $this->documents->findPair($original)
                ?? throw new ModelNotFoundException("Transfer pair of {$id} not found.");
            [$out, $in] = $original->getType() === ItemDocumentType::TRANSFER_OUT ? [$original, $pair] : [$pair, $original];

            return $this->ledger->reverseTransfer($out, $in, $reason, now()->toDateTimeString(), $idempotencyKey, $userId);
        }

        return $this->ledger->reverse($original, "el movimiento N.º {$original->getNumber()}", $reason, now()->toDateTimeString(), $idempotencyKey, $userId);
    }
}
