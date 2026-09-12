<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Interfaces\ICompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant company from the `X-Company-ID` header.
 *
 * Security (ADR-7): when the request carries valid credentials, the requested company
 * is verified against the `company_user` pivot before the context is granted. Without
 * this check any authenticated user could read and write another company's records by
 * simply changing a request header (tenancy IDOR).
 */
class CompanyContextMiddleware
{
    public function __construct(
        private readonly ICompanyContext $companyContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rawCompanyId = $request->header('X-Company-ID');

        if ($rawCompanyId === null || $rawCompanyId === '') {
            return $next($request);
        }

        $companyId = (int) $rawCompanyId;

        if ($companyId <= 0) {
            return $next($request);
        }

        // Resolve the bearer identity eagerly: this middleware runs before the route-level
        // `auth:sanctum` guard, so `$request->user()` is still null at this point.
        $user = $request->user() ?? auth('sanctum')->user();

        if ($user !== null && !$this->userBelongsToCompany((int) $user->getAuthIdentifier(), $companyId)) {
            return response()->json([
                'message' => 'You are not a member of the requested company.',
            ], Response::HTTP_FORBIDDEN);
        }

        // NOTE: legacy endpoints in routes/api.php are still declared outside `auth:sanctum`.
        // For those the context is granted as before to avoid dropping the tenant scope
        // entirely (which would widen, not narrow, the data exposed). Moving them behind
        // the guard is tracked as a separate hardening task.
        $this->companyContext->setCompanyId($companyId);

        return $next($request);
    }

    private function userBelongsToCompany(int $userId, int $companyId): bool
    {
        return DB::table('company_user')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->exists();
    }
}
