<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Core\Interfaces\ICompanyContext;

/**
 * exists/unique rules scoped to the active company (D15, layer 3), so a reference to another
 * company's record gets a clear validation error instead of a database one.
 */
trait ScopesToActiveCompany
{
    private function companyId(): ?int
    {
        return app(ICompanyContext::class)->getCompanyId();
    }

    /** Upper-cases and trims the code so the unique rule compares as the entity does. */
    private function normalizeCode(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
