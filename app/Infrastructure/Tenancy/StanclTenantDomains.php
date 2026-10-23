<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Core\Interfaces\ITenantDomains;

final class StanclTenantDomains implements ITenantDomains
{
    public function servesHostname(string $hostname): bool
    {
        $tenant = tenant();

        return $tenant !== null && $tenant->domains()->where('domain', strtolower($hostname))->exists();
    }
}
