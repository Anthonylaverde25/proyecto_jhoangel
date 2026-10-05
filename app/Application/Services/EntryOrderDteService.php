<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\EntryOrders\LoadDteDTO;
use App\Core\Entities\EntryOrderAnimalEntity;
use App\Core\Entities\EntryOrderDteEntity;
use App\Core\Entities\EntryOrderEntity;
use App\Core\Entities\EntryOrderIncidentEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\EntryDteValidationException;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;
use App\Core\ValueObjects\CaravanProvenance;
use App\Models\Caravan;
use App\Models\Farm;

/**
 * The only way caravans enter through an entry order: loading the official transit document (DTE)
 * that lists them. Used by "Cargar DTE" and by "Registrar ingreso".
 *
 * The DTE is usually downloaded before the animals travel, so its caravans are created in the
 * order's batch but in transit: no entry date, no weight, no PURCHASE movement. They come into
 * possession when they are received (EntryOrderReceptionService). Their provenance is the order's.
 *
 * Everything is checked before anything is written, and every problem is reported at once, by
 * row. What the order declared is inherited, never asked again: category always, sex unless the
 * troop is of both sexes, breed unless it has several. Head in excess are not an error: the DTE
 * is loaded and an incident is raised.
 *
 * Must run inside the transaction that saves the order: it creates caravans, and leaves the DTE
 * recorded on the order for the repository to store.
 */
final class EntryOrderDteService
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
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

        $incidents = $order->recordDte(new EntryOrderDteEntity(
            id: null,
            dteNumber: $dto->dteNumber,
            dteDate: $dto->dteDate,
            animals: $animals,
            loadedByUserId: $userId,
            observations: $dto->observations
        ), $userId);

        foreach ($incidents as $incident) {
            $warnings[] = [
                'code' => $incident->getType()->value,
                'message' => $incident->getDetail() . ' Se registró una novedad para revisar con el proveedor.',
            ];
        }

        return [
            'warnings' => $warnings,
            'metadata' => [
                'dte_number' => $dto->dteNumber,
                'head_count' => count($animals),
                'with_dte_total' => $order->withDteCount(),
                'pending_dte' => $order->pendingDteCount(),
                'incidents' => array_map(fn (EntryOrderIncidentEntity $i) => $i->getType()->value, $incidents),
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
        } elseif ($dto->dteDate < $troop->purchaseDate) {
            $header[] = $this->error('dte_date', 'DTE_BEFORE_PURCHASE', "El DTE no pudo emitirse antes de la compra ({$troop->purchaseDate}).");
        }

        if ($dto->animals === []) {
            $header[] = $this->error('animals', 'DTE_EMPTY', 'El DTE no trae caravanas.');
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
        }

        if ($header !== [] || $rows !== []) {
            throw new EntryDteValidationException('El DTE tiene datos para corregir antes de cargarlo.', $header, $rows);
        }

        return $warnings;
    }

    /**
     * Creates the caravans in the order's batch, in transit: they get their entry date, weight and
     * PURCHASE movement when they are received.
     *
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
                'entry_weight' => null,
                'entry_date' => null,
                'provenance_metadata' => $provenance,
            ]);

            $animals[] = new EntryOrderAnimalEntity(
                id: null,
                caravanId: (int) $caravan->id,
                identification: $row['caravana'],
                sex: $sex,
                breedPosition: $breedLine?->getPosition(),
                caravanMovementId: null
            );
        }

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
