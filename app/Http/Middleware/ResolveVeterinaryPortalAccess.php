<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Enums\VeterinaryPortalAccessMode;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Core\Interfaces\IVeterinarianRepository;
use App\Core\Interfaces\IVeterinaryPortalAccessTokenRepository;
use App\Infrastructure\Veterinary\VeterinaryPortalContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single door to the veterinary portal, reachable three ways:
 *
 *  1. An in-system user holding `company_user.role = 'veterinarian'` and linked to a row in
 *     the `veterinarians` catalogue (the producer's own staff vet).
 *  2. An external professional carrying a temporary access token, sent as `X-Vet-Access-Token`
 *     or as the `access_token` query parameter. That grant also establishes the tenant company,
 *     since such a request has no `X-Company-ID`.
 *  3. A management user of the establishment opening a professional's portal to SEE it, by
 *     naming them in `veterinarian_id`. That session is READ ONLY: it reaches every screen and
 *     writes nothing.
 *
 * The third door exists because the producer needs to see the work of each professional without
 * asking them for a screenshot. It stops at reading on purpose: signing, dispatching and
 * reporting are acts somebody attests to, and a manager acting in a professional's name would
 * put their licence under a document they never saw.
 *
 * Whichever door was used, downstream code depends only on IVeterinaryPortalContext.
 */
class ResolveVeterinaryPortalAccess
{
    public function __construct(
        private readonly VeterinaryPortalContext $portalContext,
        private readonly ICompanyContext $companyContext,
        private readonly IVeterinaryPortalAccessTokenRepository $tokenRepository,
        private readonly IVeterinarianRepository $veterinarianRepository,
        private readonly IDiagnosticProtocolRepository $protocolRepository
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $this->extractToken($request);

        if ($plainToken !== null) {
            return $this->handleTokenAccess($request, $next, $plainToken);
        }

        return $this->handleAuthenticatedAccess($request, $next);
    }

    private function extractToken(Request $request): ?string
    {
        $token = $request->header('X-Vet-Access-Token') ?? $request->query('access_token');

        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);

        return $token === '' ? null : $token;
    }

    private function handleTokenAccess(Request $request, Closure $next, string $plainToken): Response
    {
        $grant = $this->tokenRepository->findUsableByPlainToken($plainToken);

        if ($grant === null) {
            // Unknown, revoked, expired and exhausted all look the same from outside.
            return response()->json([
                'message' => 'El enlace de acceso no es válido o ha expirado. Solicite uno nuevo al establecimiento.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // The token, not a client supplied header, is what establishes the tenant company.
        $this->companyContext->setCompanyId($grant->getCompanyId());

        // ADR-15: the grant names the institution that answers for these samples. It is what the
        // laboratory sees on entering, and it may differ from the professional's affiliation.

        $this->portalContext->resolve(
            accessMode: VeterinaryPortalAccessMode::TEMPORARY_TOKEN,
            companyId: $grant->getCompanyId(),
            veterinarianId: $grant->getVeterinarianId(),
            veterinarianName: (string) $grant->getVeterinarianName(),
            licenseNumber: (string) $grant->getLicenseNumber(),
            allowedBatchIds: $grant->getAllowedBatchIds(),
            userId: null,
            accessTokenId: $grant->getId(),
            // ADR-16: a grant issued for one act sees that act and nothing else.
            allowedProtocolIds: $grant->getAllowedProtocolIds()
        );

        $this->tokenRepository->registerUsage((int) $grant->getId(), $request->ip());

        return $next($request);
    }

    private function handleAuthenticatedAccess(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? auth('sanctum')->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Autenticación requerida para operar el portal veterinario.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $companyId = $this->companyContext->getCompanyId();

        if ($companyId === null) {
            return response()->json([
                'message' => 'No se pudo determinar la compañía activa (X-Company-ID).',
            ], Response::HTTP_FORBIDDEN);
        }

        $userId = (int) $user->getAuthIdentifier();

        // ADR-7: the role lives on the existing company_user pivot; no new roles table.
        $hasRole = DB::table('company_user')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('role', 'veterinarian')
            ->exists();

        $veterinarian = $this->veterinarianRepository->findByUserId($userId, $companyId);
        $readOnly = false;

        if (!$hasRole || $veterinarian === null) {
            // Door 3: a manager looking at somebody's portal. Never an operator acting as them.
            $observed = $this->resolveObservedVeterinarian($request, $userId, $companyId);

            if ($observed === null) {
                return response()->json([
                    'message' => 'El usuario no está habilitado como veterinario en esta compañía.',
                ], Response::HTTP_FORBIDDEN);
            }

            $veterinarian = $observed;
            $readOnly = true;
        }

        $veterinarianId = (int) $veterinarian->getId();

        // ADR-14: two sources feed visibility, and they answer different questions.
        //   - the assignment table is the forward looking work order ("this troop is yours");
        //   - the acts are the record of what the professional actually handled.
        // A vet who worked a chute must keep seeing those bulls even if the assignment was
        // closed afterwards, so the union — not either one alone — is the rule.
        $allowedBatchIds = array_values(array_unique(array_merge(
            $this->veterinarianRepository->findActiveBatchIds($veterinarianId, $companyId),
            $this->protocolRepository->findBatchIdsWithActsFor($veterinarianId, $companyId)
        )));


        $this->portalContext->resolve(
            accessMode: VeterinaryPortalAccessMode::INTERNAL_USER,
            companyId: $companyId,
            veterinarianId: $veterinarianId,
            veterinarianName: $veterinarian->getName(),
            licenseNumber: $veterinarian->getLicenseNumber(),
            allowedBatchIds: $allowedBatchIds,
            userId: $userId,
            accessTokenId: null,
            allowedProtocolIds: [],
            readOnly: $readOnly
        );

        return $next($request);
    }

    /**
     * The professional whose portal a management user asked to see.
     *
     * Requires an explicit `veterinarian_id`: there is no "the portal" in the abstract, only
     * somebody's portal, and making the caller name them keeps the reading attributable.
     */
    private function resolveObservedVeterinarian(Request $request, int $userId, int $companyId): ?object
    {
        $canManage = DB::table('company_user')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->whereIn('role', ['admin', 'owner', 'operator'])
            ->exists();

        if (!$canManage) {
            return null;
        }

        $veterinarianId = $request->header('X-View-Veterinarian-Id') ?? $request->query('veterinarian_id');

        if (!is_numeric($veterinarianId)) {
            return null;
        }

        return $this->veterinarianRepository->findById((int) $veterinarianId, $companyId);
    }

    /**
     * Falls back to the professional's own affiliation when the grant does not name one.
     *
     * @return array{id: ?int, name: ?string, code: ?string}
     */
}
