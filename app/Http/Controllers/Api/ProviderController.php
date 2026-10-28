<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\CreatePartnerDTO;
use App\Application\DTOs\PartnerContactDTO;
use App\Application\DTOs\UpdatePartnerDTO;
use App\Application\UseCases\Partners\PartnerUseCases;
use App\Core\Enums\PartnerRole;
use App\Core\Exceptions\PartnerException;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Providers\CreateProviderRequest;
use App\Http\Requests\Providers\UpdateProviderRequest;
use App\Http\Requests\Providers\UpdateProviderRolesRequest;
use App\Http\Resources\PartnerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transitional alias of /partners for clients still speaking "providers" (egreso plan v5, 03 §2):
 * the supplier flags become roles and email/phone become the default contact. Remove it once no
 * client calls /providers.
 */
class ProviderController extends Controller
{
    use ResolvesActiveCompany;

    private const LEGACY_CONTACT_NAME = 'Principal';

    public function __construct(
        private readonly PartnerUseCases $partners
    ) {
    }

    /** Only suppliers, as before; `supplies` narrows to one of the two. */
    public function index(Request $request): JsonResponse
    {
        $roles = match ($request->query('supplies')) {
            'livestock' => [PartnerRole::SUPPLIER_LIVESTOCK],
            'items' => [PartnerRole::SUPPLIER_ITEMS],
            default => PartnerRole::supplierRoles(),
        };

        return response()->json(PartnerResource::collection(($this->partners->list)($roles)));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(new PartnerResource(($this->partners->find)($id)));
    }

    public function store(CreateProviderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $roles = self::supplierRoles((bool) ($data['supplies_livestock'] ?? true), (bool) ($data['supplies_items'] ?? false));
        if ($roles === []) {
            throw PartnerException::withoutRole();
        }
        $data['roles'] = array_map(fn (PartnerRole $role) => $role->value, $roles);
        if (self::filled($data['phone'] ?? null)) {
            $data['contact'] = ['name' => self::LEGACY_CONTACT_NAME, 'phone' => $data['phone'], 'email' => $data['email'] ?? null];
        }

        $entity = ($this->partners->create)(CreatePartnerDTO::fromArray($data, $this->activeCompanyId()));

        return response()->json(new PartnerResource($entity), 201);
    }

    /** Identity as in /partners; a phone updates the default contact, or creates it. */
    public function update(UpdateProviderRequest $request, int $id): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $data = $request->validated();
        $partner = ($this->partners->update)($id, UpdatePartnerDTO::fromArray($data), $companyId);

        if (self::filled($data['phone'] ?? null)) {
            $default = $partner->defaultContact();
            $contact = new PartnerContactDTO(
                name: $default?->getName() ?? self::LEGACY_CONTACT_NAME,
                phone: (string) $data['phone'],
                position: $default?->getPosition(),
                email: $data['email'] ?? null,
                notes: $default?->getNotes(),
            );
            $default === null
                ? ($this->partners->addContact)($id, $contact, $companyId)
                : ($this->partners->updateContact)($id, (int) $default->getId(), $contact, $companyId);
            $partner = ($this->partners->find)($id);
        }

        return response()->json(new PartnerResource($partner));
    }

    /** The two supplier flags; customer roles the partner already holds are kept. */
    public function updateRoles(UpdateProviderRolesRequest $request, int $id): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $current = ($this->partners->find)($id);
        $customer = array_values(array_filter($current->getRoles(), fn (PartnerRole $role) => $role->isCustomer()));

        $entity = ($this->partners->updateRoles)(
            $id,
            [...self::supplierRoles($request->boolean('supplies_livestock'), $request->boolean('supplies_items')), ...$customer],
            $companyId
        );

        return response()->json(new PartnerResource($entity));
    }

    /** @return list<PartnerRole> */
    private static function supplierRoles(bool $livestock, bool $items): array
    {
        return array_values(array_filter([
            $livestock ? PartnerRole::SUPPLIER_LIVESTOCK : null,
            $items ? PartnerRole::SUPPLIER_ITEMS : null,
        ]));
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
