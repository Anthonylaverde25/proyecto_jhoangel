<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Core\Entities\SemenProfileEntity;
use App\Core\Enums\SemenPreservation;
use App\Core\Enums\StrawColor;
use App\Core\Enums\StrawSize;
use App\Core\Interfaces\ISemenProfileRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** The straw data of a semen item, completed after the entry (P12). Sire and type stay as they are (SI3). */
final class UpdateSemenProfileUseCase
{
    public function __construct(
        private readonly ISemenProfileRepository $profiles,
    ) {
    }

    public function __invoke(int $itemId, SemenPreservation $preservation, ?StrawSize $size, ?StrawColor $color, ?StrawColor $cap, int $companyId): SemenProfileEntity
    {
        $profile = $this->profiles->findByItem($itemId, $companyId)
            ?? throw new ModelNotFoundException("Item {$itemId} has no semen profile.");

        return $this->profiles->save($profile->withStraw($preservation, $size, $color, $cap));
    }
}
