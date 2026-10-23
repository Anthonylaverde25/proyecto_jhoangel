<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Semen\SemenLotShareDTO;
use App\Application\DTOs\Semen\SemenReceiptDTO;
use App\Application\DTOs\Semen\SemenSireDTO;
use App\Application\UseCases\Semen\SemenUseCases;
use App\Core\Entities\SemenSireEntity;
use App\Core\Enums\SemenPreservation;
use App\Core\Enums\StrawColor;
use App\Core\Enums\StrawSize;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Semen\IdentifySemenLotRequest;
use App\Http\Requests\Semen\StoreSemenReceiptRequest;
use App\Http\Requests\Semen\StoreSemenSireRequest;
use App\Http\Requests\Semen\UpdateSemenProfileRequest;
use App\Http\Resources\ItemDocumentResource;
use App\Http\Resources\SemenProfileResource;
use App\Http\Resources\SemenSireResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Semen stock (semen S-A): sires, the semen entry, inventory, lots and their identification. */
class SemenController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly SemenUseCases $semen
    ) {
    }

    public function sires(Request $request): JsonResponse
    {
        $sires = ($this->semen->listSires)($this->activeCompanyId(), $request->query('search'), $request->boolean('include_inactive'));

        return response()->json(array_map(fn (SemenSireEntity $s) => SemenSireResource::shape($s), $sires));
    }

    public function storeSire(StoreSemenSireRequest $request): JsonResponse
    {
        $sire = ($this->semen->createSire)(SemenSireDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(SemenSireResource::shape($sire), 201);
    }

    public function updateSire(StoreSemenSireRequest $request, int $id): JsonResponse
    {
        $sire = ($this->semen->updateSire)($id, SemenSireDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(SemenSireResource::shape($sire));
    }

    public function setSireActive(Request $request, int $id): JsonResponse
    {
        $request->validate(['is_active' => ['required', 'boolean']]);
        $sire = ($this->semen->setSireActive)($id, $request->boolean('is_active'), $this->activeCompanyId());

        return response()->json(SemenSireResource::shape($sire));
    }

    public function storeReceipt(StoreSemenReceiptRequest $request): JsonResponse
    {
        $result = ($this->semen->registerReceipt)(SemenReceiptDTO::fromArray($request->validated(), $request->user()?->id), $this->activeCompanyId());

        return response()->json(ItemDocumentResource::fromLedger($result)->resolve($request), $result->replayed ? 200 : 201);
    }

    public function inventory(Request $request): JsonResponse
    {
        $warehouseId = $request->query('warehouse_id');

        return response()->json(($this->semen->inventory)(
            $this->activeCompanyId(),
            $warehouseId === null ? null : (int) $warehouseId,
            $request->boolean('with_balance'),
            $request->boolean('include_inactive'),
        ));
    }

    public function lots(Request $request): JsonResponse
    {
        $request->validate(['item_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer']]);

        return response()->json(($this->semen->listLots)((int) $request->query('item_id'), (int) $request->query('warehouse_id'), $this->activeCompanyId()));
    }

    public function lot(int $id): JsonResponse
    {
        return response()->json(($this->semen->lot)($id, $this->activeCompanyId()));
    }

    public function unidentified(Request $request): JsonResponse
    {
        $item = $request->query('item_id');
        $warehouse = $request->query('warehouse_id');

        return response()->json(($this->semen->listUnidentified)(
            $this->activeCompanyId(),
            $item === null ? null : (int) $item,
            $warehouse === null ? null : (int) $warehouse,
        ));
    }

    public function identify(IdentifySemenLotRequest $request, int $id): JsonResponse
    {
        $shares = array_map(fn (array $share) => SemenLotShareDTO::fromArray($share), array_values($request->validated('lots')));
        $entry = ($this->semen->identify)($id, $shares, $this->activeCompanyId(), $request->user()?->id);

        return response()->json([
            'id' => $entry->getId(),
            'status' => $entry->getStatus(),
            'quantity' => $entry->getQuantity(),
            'identified_quantity' => $entry->getIdentifiedQuantity(),
            'pending_quantity' => $entry->pending(),
        ]);
    }

    public function updateProfile(UpdateSemenProfileRequest $request, int $itemId): JsonResponse
    {
        $profile = ($this->semen->updateProfile)(
            $itemId,
            SemenPreservation::from((string) $request->validated('preservation')),
            $request->validated('straw_size') === null ? null : StrawSize::from((string) $request->validated('straw_size')),
            $request->validated('straw_color') === null ? null : StrawColor::from((string) $request->validated('straw_color')),
            $request->validated('cap_color') === null ? null : StrawColor::from((string) $request->validated('cap_color')),
            $this->activeCompanyId(),
        );

        return response()->json(SemenProfileResource::of($profile));
    }
}
