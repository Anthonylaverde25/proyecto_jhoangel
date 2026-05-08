<?php

declare(strict_types=1);

namespace App\Core\Contexts;

use App\Core\Interfaces\ICompanyContext;

class CompanyContext implements ICompanyContext
{
    private ?int $companyId = null;

    public function setCompanyId(int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    public function hasCompanyContext(): bool
    {
        return $this->companyId !== null;
    }
}
