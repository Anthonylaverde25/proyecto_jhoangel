<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\CreatePartnerDTO;
use App\Application\DTOs\UpdatePartnerDTO;
use App\Application\UseCases\Partners\PartnerUseCases;
use App\Core\Enums\PartnerRole;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Partners\CreatePartnerRequest;
use App\Http\Requests\Partners\UpdatePartnerRequest;
use App\Http\Requests\Partners\UpdatePartnerRolesRequest;
use App\Http\Resources\PartnerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suppliers and customers of the active company ("Clientes y proveedores").
 */
class PartnerController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly PartnerUseCases $partners
    ) {
    }

    /**
     * Filters (C3): `role` (one code or `role[]`) and `group` (supplier | customer); combined, a
     * partner matches any of the requested roles.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            PartnerResource::collection(($this->partners->list)(self::requestedRoles($request)))
        );
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(new PartnerResource(($this->partners->find)($id)));
    }

    public function store(CreatePartnerRequest $request): JsonResponse
    {
        $entity = ($this->partners->create)(CreatePartnerDTO::fromArray($request->validated(), $this->activeCompanyId()));

        return response()->json(new PartnerResource($entity), 201);
    }

    /** Corrects the identity. The CUIT changes only while it has no fiscal documents. */
    public function update(UpdatePartnerRequest $request, int $id): JsonResponse
    {
        $entity = ($this->partners->update)($id, UpdatePartnerDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(new PartnerResource($entity));
    }

    public function updateRoles(UpdatePartnerRolesRequest $request, int $id): JsonResponse
    {
        $entity = ($this->partners->updateRoles)($id, $request->roles(), $this->activeCompanyId());

        return response()->json(new PartnerResource($entity));
    }

    /** @return list<PartnerRole>|null */
    private static function requestedRoles(Request $request): ?array
    {
        $roles = array_values(array_filter(array_map(
            fn ($code) => PartnerRole::tryFrom((string) $code),
            (array) $request->query('role', [])
        )));

        $roles = match ($request->query('group')) {
            'supplier' => [...$roles, ...PartnerRole::supplierRoles()],
            'customer' => [...$roles, ...PartnerRole::customerRoles()],
            default => $roles,
        };

        return $roles === [] ? null : $roles;
    }
}
