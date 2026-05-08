<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Core\Interfaces\ICompanyContext;

class CompanyContextMiddleware
{
    public function __construct(
        private readonly ICompanyContext $companyContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header('X-Company-ID');

        if ($companyId) {
            $this->companyContext->setCompanyId((int) $companyId);
        }

        return $next($request);
    }
}
