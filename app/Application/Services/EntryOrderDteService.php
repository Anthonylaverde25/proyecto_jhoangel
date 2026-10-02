<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\EntryOrders\LoadDteDTO;
use App\Core\Entities\EntryOrderAnimalEntity;
use App\Core\Entities\EntryOrderDteEntity;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\SexComposition;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use App\Core\Services\BatchWeightService;
use App\Core\ValueObjects\CaravanProvenance;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\Farm;

/**
 * The only way caravans enter through an entry order: loading the official transit document (DTE)
 * that lists them. Used by "Cargar DTE" and by "Registrar ingreso".
 *
 * Everything is checked before anything is written, and every problem is reported at once, by
 * row. What the order declared is inherited, never asked again: category always, sex unless the
 * troop is of both sexes, breed unless it has several.
 *
 * Must run inside the transaction that saves the order: it creates caravans, weights and
 * movements, and leaves the DTE recorded on the order for the repository to store.
 */
final class EntryOrderDteService
{
    public function __construct(
        private readonly IEntryOrderRepository $repository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    /**
     * @return array{warnings: list<array{code: string, message: string, row?: int}>, metadata: array<string, mixed>}
     *
     * @throws EntryDteValidationException
     * @throws EntryOrderDomainException
     */
    public function load(EntryOrderEntity $order, LoadDteDTO $dto, ?int $userId): array
    {
        if (!$order->getStatus()->acceptsDte()) {
            throw EntryOrderDomainException::invalid(
                "La orden {$order->getCode()} está {$order->getStatus()->label()} y no admite DTE.",
                'ENTRY_ORDER_NOT_ACCEPTING_DTE'
            );
        }

        $warnings = $this->validate($order, $dto);
        $animals = $this->persist($order, $dto);

        $order->recordDte(new EntryOrderDteEntity(
            id: null,
            dteNumber: $dto->dteNumber,
            dteDate: $dto->dteDate,
            enteredAt: $dto->enteredAt,
            animals: $animals,
            loadedByUserId: $userId,
            observations: $dto->observations
        ));

        return [
            'warnings' => $warnings,
            'metadata' => [
                'dte_number' => $dto->dteNumber,
                'entered_at' => $dto->enteredAt,
                'head_count' => count($animals),
                'entered_total' => $order->enteredCount(),
                'pending' => $order->pendingCount(),
            ],
        ];
    }

    /**
     * @return list<array{code: string, message: string, row?: int}>
     *
     * @throws EntryDteValidationException
     */
    private function validate(EntryOrderEntity $order, LoadDteDTO $dto): array
    {
        $troop = $order->getTroop();
        $header = [];
        $rows = [];
        $warnings = [];
        $today = now()->toDateString();

        if ($dto->dteNumber === '') {
            $header[] = $this->error('dte_number', 'DTE_NUMBER_MISSING', 'Falta el número de DTE.');
        } elseif (($loadedIn = $this->repository->orderCodeOfDte($dto->dteNumber, $order->getCompanyId())) !== null) {
            $header[] = $this->error('dte_number', 'DTE_ALREADY_LOADED', "El DTE {$dto->dteNumber} ya está cargado en la orden {$loadedIn}.");
        }

        if ($dto->dteDate > $today) {
            $header[] = $this->error('dte_date', 'DATE_IN_FUTURE', 'La fecha del DTE no puede ser futura.');
        }

        if ($dto->enteredAt > $today) {
            $header[] = $this->error('entered_at', 'DATE_IN_FUTURE', 'La fecha de ingreso no puede ser futura.');
        } elseif ($dto->enteredAt < $troop->purchaseDate) {
            $header[] = $this->error('entered_at', 'ENTERED_BEFORE_PURCHASE', "La hacienda no pudo ingresar antes de la compra ({$troop->purchaseDate}).");
        } elseif ($dto->enteredAt < $dto->dteDate) {
            $header[] = $this->error('entered_at', 'ENTERED_BEFORE_DTE', 'La hacienda no pudo ingresar antes de que se emitiera su DTE.');
        }

        if ($dto->animals === []) {
            $header[] = $this->error('animals', 'DTE_EMPTY', 'El DTE no trae caravanas.');
        }

        $pending = $order->pendingCount();
        if (count($dto->animals) > $pending) {
            $header[] = $this->error(
                'animals',
                'HEAD_COUNT_EXCEEDED',
                "La orden es por {$troop->headCount} cabezas y quedan {$pending} por ingresar; el DTE trae " . count($dto->animals) . '.'
            );
        }

        $inherited = $troop->sexComposition->inheritedSex();
        $breeds = $troop->breedsByPosition();
        $seen = [];
        $existing = Caravan::withoutGlobalScopes()
            ->whereIn('identification', array_filter(array_column($dto->animals, 'caravana')))
            ->pluck('identification')
            ->map(fn ($id) => mb_strtoupper((string) $id))
            ->flip();

        foreach ($dto->animals as $i => $row) {
            $tag = $row['caravana'];
            $upper = mb_strtoupper($tag);

            if ($tag === '') {
                $rows[] = $this->rowError($i, 'caravana', 'CARAVAN_MISSING', 'Falta el número de caravana.');
            } elseif (isset($seen[$upper])) {
                $rows[] = $this->rowError($i, 'caravana', 'CARAVAN_DUPLICATED', "La caravana {$tag} está repetida en el DTE (fila " . ($seen[$upper] + 1) . ').');
            } elseif (isset($existing[$upper])) {
                $rows[] = $this->rowError($i, 'caravana', 'CARAVAN_EXISTS', "La caravana {$tag} ya existe en el sistema.");
            }

            if ($tag !== '') {
                $seen[$upper] ??= $i;
            }

            if ($inherited === null) {
                if ($row['sex'] === null) {
                    $rows[] = $this->rowError($i, 'sex', 'SEX_MISSING', 'La tropa es de ambos sexos: indicá si es macho (M) o hembra (H).');
                } elseif (AnimalSex::tryFrom($row['sex']) === null) {
                    $rows[] = $this->rowError($i, 'sex', 'SEX_INVALID', "'{$row['sex']}' no es un sexo: M o H.");
                }
            } elseif ($row['sex'] !== null && $row['sex'] !== $inherited->value) {
                $rows[] = $this->rowError($i, 'sex', 'SEX_CONTRADICTS_ORDER', "La orden es de {$troop->sexComposition->label()}.");
            }

            if ($row['breed_position'] !== null && !isset($breeds[$row['breed_position']])) {
                $rows[] = $this->rowError($i, 'breed_position', 'BREED_UNKNOWN', 'La orden no declara esa raza.');
            } elseif ($row['breed_position'] === null && $troop->hasSeveralBreeds()) {
                $warnings[] = ['row' => $i, 'code' => 'BREED_UNDECLARED', 'message' => "La caravana {$tag} queda sin raza declarada."];
            }

            $weight = $row['weight'];
            if ($weight !== null && $weight <= 0) {
                $rows[] = $this->rowError($i, 'weight', 'WEIGHT_INVALID', 'El peso tiene que ser mayor que cero.');
            } elseif ($weight !== null && (($troop->minWeight !== null && $weight < $troop->minWeight) || ($troop->maxWeight !== null && $weight > $troop->maxWeight))) {
                $warnings[] = [
                    'row' => $i,
                    'code' => 'WEIGHT_OUT_OF_RANGE',
                    'message' => "{$weight} kg está fuera del rango declarado en la compra ("
                        . ($troop->minWeight ?? '—') . ' a ' . ($troop->maxWeight ?? '—') . ' kg). Revisá la lectura.',
                ];
            }
        }

        if ($troop->sexComposition === SexComposition::MIXED) {
            foreach ([[AnimalSex::MALE, (int) $troop->maleCount, 'machos'], [AnimalSex::FEMALE, (int) $troop->femaleCount, 'hembras']] as [$sex, $declared, $word]) {
                $after = $order->enteredCountBySex($sex->value) + count(array_filter($dto->animals, fn ($r) => $r['sex'] === $sex->value));

                if ($after > $declared) {
                    $header[] = $this->error('animals', 'SEX_COUNT_EXCEEDED', "La orden declara {$declared} {$word} y con este DTE serían {$after}.");
                }
            }
        }

        if ($header !== [] || $rows !== []) {
            throw new EntryDteValidationException('El DTE tiene datos para corregir antes de cargarlo.', $header, $rows);
        }

        return $warnings;
    }

    /**
     * @return EntryOrderAnimalEntity[]
     */
    private function persist(EntryOrderEntity $order, LoadDteDTO $dto): array
    {
        $troop = $order->getTroop();
        $batchId = (int) $order->getBatchId();
        $breeds = $troop->breedsByPosition();
        $singleBreed = count($breeds) === 1 ? reset($breeds) : null;
        $inherited = $troop->sexComposition->inheritedSex();
        $renspa = (string) (Farm::withoutGlobalScopes()->whereKey($troop->farmId)->value('renspa') ?? 'NO_DEFINIDO');

        $provenance = (new CaravanProvenance(
            originRenspa: $renspa,
            originProviderId: $troop->providerId,
            dteNumber: $dto->dteNumber,
            auctionName: $troop->auctionNumber,
            extraData: ['entry_order_code' => $order->getCode(), 'source' => 'ENTRY_ORDER']
        ))->toArray();

        $animals = [];

        foreach ($dto->animals as $row) {
            $sex = $inherited?->value ?? (string) $row['sex'];
            $breedLine = $row['breed_position'] !== null ? $breeds[$row['breed_position']] : $singleBreed;

            $caravan = Caravan::create([
                'company_id' => $order->getCompanyId(),
                'batch_id' => $batchId,
                'provider_id' => $troop->providerId,
                'renspa' => $renspa,
                'identification' => $row['caravana'],
                'category_id' => $troop->categoryId,
                'subcategory_id' => null,
                'sex' => $sex,
                'teeth' => 0,
                'breed_id' => $breedLine?->getBreedId(),
                'color_id' => $breedLine?->getColorId(),
                'entry_weight' => $row['weight'],
                'entry_date' => $dto->enteredAt,
                'provenance_metadata' => $provenance,
            ]);

            if ($row['weight'] !== null) {
                CaravanWeight::create([
                    'caravan_id' => $caravan->id,
                    'weight' => $row['weight'],
                    'current' => true,
                    'weighing_date' => $dto->enteredAt,
                    'notes' => "Pesaje de ingreso ({$order->getCode()}, DTE {$dto->dteNumber})",
                ]);
            }

            $movement = CaravanMovement::create([
                'caravan_id' => $caravan->id,
                'company_id' => $order->getCompanyId(),
                'to_batch_id' => $batchId,
                'provider_id' => $troop->providerId,
                'renspa' => $renspa,
                'from_renspa' => $renspa,
                'type' => 'PURCHASE',
                'movement_date' => $dto->enteredAt,
                'provenance_metadata' => $provenance,
                'observations' => "Ingreso por DTE {$dto->dteNumber} de la orden {$order->getCode()}",
            ]);

            $animals[] = new EntryOrderAnimalEntity(
                id: null,
                caravanId: (int) $caravan->id,
                identification: $row['caravana'],
                sex: $sex,
                breedPosition: $breedLine?->getPosition(),
                caravanMovementId: (int) $movement->id,
                entryWeight: $row['weight']
            );
        }

        // Once per DTE, not per caravan: a single point in the batch's series for the whole arrival.
        $this->batchWeightService->recalculateBatchWeight($batchId, BatchWeightCause::MOVEMENT_IN, new \DateTimeImmutable($dto->enteredAt));

        return $animals;
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function error(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }

    /**
     * @return array{row: int, field: string, code: string, message: string}
     */
    private function rowError(int $row, string $field, string $code, string $message): array
    {
        return ['row' => $row, 'field' => $field, 'code' => $code, 'message' => $message];
    }
}
