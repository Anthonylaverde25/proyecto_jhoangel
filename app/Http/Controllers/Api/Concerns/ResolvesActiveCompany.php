<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ICompanyContext;

/**
 * Company-owned catalogs make no sense without an active company: refuse instead of leaking
 * every company's rows.
 */
trait ResolvesActiveCompany
{
    private function activeCompanyId(): int
    {
        return app(ICompanyContext::class)->getCompanyId()
            ?? throw new DomainException('Falta la empresa activa (X-Company-ID).');
    }
}
