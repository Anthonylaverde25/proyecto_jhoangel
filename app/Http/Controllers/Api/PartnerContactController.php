<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\PartnerContactDTO;
use App\Application\UseCases\Partners\PartnerUseCases;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Partners\PartnerContactRequest;
use App\Http\Resources\PartnerContactResource;
use App\Http\Resources\PartnerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Contact persons of a partner (C6–C9).
 */
class PartnerContactController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly PartnerUseCases $partners
    ) {
    }

    public function store(PartnerContactRequest $request, int $id): JsonResponse
    {
        $contact = ($this->partners->addContact)($id, PartnerContactDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(new PartnerContactResource($contact), 201);
    }

    public function update(PartnerContactRequest $request, int $id, int $contactId): JsonResponse
    {
        $contact = ($this->partners->updateContact)($id, $contactId, PartnerContactDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(new PartnerContactResource($contact));
    }

    /** Answers with the partner: two contacts changed. */
    public function setDefault(int $id, int $contactId): JsonResponse
    {
        $partner = ($this->partners->setDefaultContact)($id, $contactId, $this->activeCompanyId());

        return response()->json(new PartnerResource($partner));
    }

    public function destroy(int $id, int $contactId): Response
    {
        ($this->partners->removeContact)($id, $contactId, $this->activeCompanyId());

        return response()->noContent();
    }
}
