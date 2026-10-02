<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\EntryOrders\StoreEntryOrderDTO;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\BatchNameMode;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\ValueObjects\EntryTroop;

/**
 * Builds entry orders and confirms purchases. Shared by "Nueva orden de ingreso" (draft or
 * confirmed) and "Registrar ingreso" (confirmed and loaded with its DTE in the same transaction).
 *
 * Must run inside a transaction: the code and number are read with a lock, and confirming creates
 * the batch.
 */
final class EntryOrderFactory
{
    public function __construct(
        private readonly EntryOrderValidator $validator,
        private readonly EntryOrderCodeGenerator $codes,
        private readonly EntryOrderBatchNamer $namer,
        private readonly EntryOrderBatchFactory $batchFactory
    ) {
    }

    /**
     * @return array{order: EntryOrderEntity, warnings: list<array{code: string, message: string}>} not saved
     *
     * @throws EntryOrderDomainException
     */
    public function build(StoreEntryOrderDTO $dto, TransferOrderKind $kind, bool $confirm): array
    {
        $troop = $this->troopOf($dto);
        $number = $this->codes->nextNumber($dto->companyId);

        $order = EntryOrderEntity::draft(
            companyId: $dto->companyId,
            code: $this->codes->nextCode($dto->companyId, new \DateTimeImmutable()),
            number: $number,
            kind: $kind,
            troop: $troop,
            batchName: $this->nameFor($dto, $troop, $number),
            batchNameMode: $dto->batchNameMode,
            requestedByUserId: $dto->userId
        );

        $warnings = $confirm ? $this->confirm($order) : $this->draftNameWarnings($order);

        return ['order' => $order, 'warnings' => $warnings];
    }

    /**
     * The troop of the form, checked against the catalogues.
     *
     * @throws EntryOrderDomainException
     */
    public function troopOf(StoreEntryOrderDTO $dto): EntryTroop
    {
        $troop = $dto->toTroop();
        $this->validator->assertValid($troop, $dto->companyId);

        return $troop;
    }

    /**
     * The batch name the order will carry: composed from the auction in AUTO mode, as written otherwise.
     *
     * @throws EntryOrderDomainException
     */
    public function nameFor(StoreEntryOrderDTO $dto, EntryTroop $troop, int $number): string
    {
        if ($dto->batchNameMode !== BatchNameMode::AUTO) {
            return (string) $dto->batchName;
        }

        if ($troop->auctionNumber === null) {
            throw EntryOrderDomainException::invalid(
                'El nombre automático se arma con la terminación de subasta: cargala o escribí un nombre.',
                'BATCH_NAME_NEEDS_AUCTION',
                'batch_name'
            );
        }

        return EntryOrderBatchNamer::compose($troop->auctionNumber, $number);
    }

    /**
     * Draft → awaiting DTE: checks the name once more (another batch may have taken it since the
     * draft was saved), creates the empty external batch and confirms.
     *
     * @return list<array{code: string, message: string}>
     *
     * @throws EntryOrderDomainException
     */
    public function confirm(EntryOrderEntity $order): array
    {
        $warnings = $this->namer->check($order->getBatchName(), $order->getTroop()->farmId);
        $order->confirm($this->batchFactory->create($order));

        return $warnings;
    }

    /**
     * A draft is not blocked by its name: it is checked for real when the purchase is confirmed.
     * Until then a clash is reported as advice.
     *
     * @return list<array{code: string, message: string}>
     */
    public function draftNameWarnings(EntryOrderEntity $order): array
    {
        try {
            return $this->namer->check($order->getBatchName(), $order->getTroop()->farmId);
        } catch (EntryOrderDomainException $e) {
            return [['code' => $e->getErrorCode(), 'message' => $e->getMessage() . ' Hay que cambiarlo antes de confirmar la compra.']];
        }
    }
}
