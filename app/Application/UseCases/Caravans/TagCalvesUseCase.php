<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\Caravans\TagCalvesDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\TagCalvesException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Caravanear crías": puts the tag on calves that were born without one. Every row is checked
 * before anything is written, like a sheet; then all of them are tagged in one transaction.
 */
final class TagCalvesUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * @return list<CaravanEntity> the calves, now tagged
     *
     * @throws TagCalvesException
     */
    public function __invoke(TagCalvesDTO $dto): array
    {
        $errors = [];
        $calves = [];
        $seen = [];

        $inUse = $this->caravanRepository->findByIdentifications(array_column($dto->items, 'identification'));

        foreach ($dto->items as $index => $item) {
            $field = "items.{$index}.identification";
            $tag = $item['identification'];
            $upper = mb_strtoupper($tag);
            $calf = $this->caravanRepository->findById($item['caravan_id']);

            if ($calf === null) {
                $errors["items.{$index}.caravan_id"][] = 'La cría no existe.';
                continue;
            }

            if ($calf->isDead()) {
                $errors[$field][] = "La " . lcfirst($calf->label()) . " está registrada como muerta: no se caravanea.";
                continue;
            }

            if (isset($seen[$upper])) {
                $errors[$field][] = "La caravana '{$tag}' figura dos veces en la carga.";
            } elseif (isset($inUse[$upper])) {
                $errors[$field][] = "Ya existe un animal con la caravana '{$tag}'.";
            }
            $seen[$upper] = true;

            try {
                $calf->assignTag(new CaravanNumber($tag), $dto->taggedAt);
            } catch (DomainException $e) {
                $errors[$field][] = $e->getMessage();
                continue;
            }

            $calves[] = $calf;
        }

        if ($errors !== []) {
            throw TagCalvesException::many($errors);
        }

        try {
            return DB::transaction(fn (): array => array_map(
                fn (CaravanEntity $calf): CaravanEntity => $this->caravanRepository->save($calf),
                $calves
            ));
        } catch (UniqueConstraintViolationException) {
            // Another load took one of the tags meanwhile.
            throw TagCalvesException::on('items', 'Una de las caravanas se cargó recién en otro animal. Actualizá y volvé a confirmar.');
        }
    }
}
