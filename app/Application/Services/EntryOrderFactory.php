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
 * The animals go to the own batches the order declares; only what has no destination goes to a
 * transit batch, created when the purchase is confirmed — and only then is its name demanded.
 *
 * Must run inside a transaction: the code and number are read with a lock, and confirming may
 * create the transit batch.
 */
final class EntryOrderFactory
{
    public function __construct(
        private readonly EntryOrderValidator $validator,
        private readonly EntryOrderCodeGenerator $codes,
        private readonly EntryOrderBatchNamer $namer,
        private readonly EntryOrderBatchFactory $batchFactory,
        private readonly EntryOrderDestinationValidator $destinationValidator
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
        $destinations = $dto->toDestinations();
        $this->destinationValidator->assertUsable($destinations->batchIds());
        $number = $this->codes->nextNumber($dto->companyId);

        $order = EntryOrderEntity::draft(
            companyId: $dto->companyId,
            code: $this->codes->nextCode($dto->companyId, new \DateTimeImmutable()),
            number: $number,
            kind: $kind,
            troop: $troop,
            batchName: $this->nameFor($dto, $troop, $number),
            batchNameMode: $dto->batchNameMode,
            requestedByUserId: $dto->userId,
            destinations: $destinations
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
        return $this->validator->assertValid($dto->toTroop(), $dto->companyId);
    }

    /**
     * The name of the transit batch the order will carry: composed from the auction in AUTO mode,
     * as written otherwise. Not demanded when every animal has a destination: there is no transit
     * batch to name.
     *
     * @throws EntryOrderDomainException
     */
    public function nameFor(StoreEntryOrderDTO $dto, EntryTroop $troop, int $number): ?string
    {
        if ($dto->batchNameMode !== BatchNameMode::AUTO) {
            return $dto->batchName;
        }

        if ($troop->auctionNumber === null) {
            // A draft can wait for the auction; confirming demands the name, if there is a batch to name.
            if (!$dto->confirm || $dto->toDestinations()->coversAll($troop)) {
                return null;
            }

            throw EntryOrderDomainException::invalid(
                'El nombre automático se arma con la terminación de subasta: cargala o escribí un nombre.',
                'BATCH_NAME_NEEDS_AUCTION',
                'batch_name'
            );
        }

        return EntryOrderBatchNamer::compose($troop->auctionNumber, $number);
    }

    /**
     * Draft → awaiting DTE: the destinations are checked again (a batch may have been closed since
     * the draft was saved) and, when some animal has none, the name of the transit batch too, which
     * is created empty. Then the order is confirmed.
     *
     * @return list<array{code: string, message: string}>
     *
     * @throws EntryOrderDomainException
     */
    public function confirm(EntryOrderEntity $order): array
    {
        $order->assertReadyToConfirm();
        $this->destinationValidator->assertUsable($order->getDestinations()->batchIds());

        if (!$order->needsTransitBatch()) {
            $order->confirm(null);

            return [];
        }

        $warnings = $this->namer->check((string) $order->getBatchName(), $order->getTroop()->farmId);
        $order->confirm($this->batchFactory->create($order));

        return $warnings;
    }

    /**
     * The transit batch a confirmed order needs after a redistribution left animals without a
     * destination.
     *
     * @return list<array{code: string, message: string}>
     *
     * @throws EntryOrderDomainException
     */
    public function attachTransitBatchIfNeeded(EntryOrderEntity $order): array
    {
        if (!$order->lacksTransitBatch()) {
            return [];
        }

        $warnings = $this->namer->check((string) $order->getBatchName(), $order->getTroop()->farmId);
        $order->attachTransitBatch($this->batchFactory->create($order));

        return $warnings;
    }

    /**
     * A draft is not blocked by its name: it is checked for real when the purchase is confirmed.
     * Until then a clash is reported as advice. Without a transit batch to create, there is nothing
     * to check.
     *
     * @return list<array{code: string, message: string}>
     */
    public function draftNameWarnings(EntryOrderEntity $order): array
    {
        if ($order->getBatchName() === null || !$order->needsTransitBatch()) {
            return [];
        }

        try {
            return $this->namer->check($order->getBatchName(), $order->getTroop()->farmId);
        } catch (EntryOrderDomainException $e) {
            return [['code' => $e->getErrorCode(), 'message' => $e->getMessage() . ' Hay que cambiarlo antes de confirmar la compra.']];
        }
    }
}
