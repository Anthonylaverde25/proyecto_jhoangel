<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

/** Aggregator of the semen stock (semen S-A), so the controller stays thin. */
final class SemenUseCases
{
    public function __construct(
        public readonly ListSemenSiresUseCase $listSires,
        public readonly CreateSemenSireUseCase $createSire,
        public readonly UpdateSemenSireUseCase $updateSire,
        public readonly SetSemenSireActiveUseCase $setSireActive,
        public readonly RegisterSemenReceiptUseCase $registerReceipt,
        public readonly GetSemenInventoryUseCase $inventory,
        public readonly ListSemenLotsUseCase $listLots,
        public readonly GetSemenLotUseCase $lot,
        public readonly UpdateSemenProfileUseCase $updateProfile,
        public readonly ListSemenUnidentifiedUseCase $listUnidentified,
        public readonly IdentifySemenLotUseCase $identify,
    ) {
    }
}
