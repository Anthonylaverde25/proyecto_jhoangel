<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

/**
 * The domains through which the current tenant is reached.
 */
interface ITenantDomains
{
    public function servesHostname(string $hostname): bool;
}
