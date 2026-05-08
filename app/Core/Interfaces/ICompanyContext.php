<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

interface ICompanyContext
{
    public function setCompanyId(int $companyId): void;
    public function getCompanyId(): ?int;
    public function hasCompanyContext(): bool;
}
