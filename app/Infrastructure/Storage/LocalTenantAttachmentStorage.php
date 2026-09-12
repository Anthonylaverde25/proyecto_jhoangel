<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Core\Interfaces\IProtocolAttachmentStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ADR-6: writes sanitary evidence to the private `tenant` disk, partitioned by tenant and
 * company. Nothing here is reachable over HTTP: the only read path is the signed download
 * endpoint guarded by DiagnosticProtocolPolicy.
 */
final class LocalTenantAttachmentStorage implements IProtocolAttachmentStorage
{
    public function store(int $companyId, int $protocolId, string $contents, string $extension): string
    {
        $path = sprintf(
            '%s/companies/%d/protocols/%d/%s.%s',
            $this->tenantSegment(),
            $companyId,
            $protocolId,
            (string) Str::uuid(),
            strtolower($extension)
        );

        $this->disk()->put($path, $contents);

        return $path;
    }

    public function delete(string $path): void
    {
        if ($this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function absolutePath(string $path): string
    {
        return $this->disk()->path($path);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('livestock.attachments.disk', 'tenant'));
    }

    /**
     * Keeps one tenant's evidence physically apart from another's, even though every tenant
     * already owns its own database.
     */
    private function tenantSegment(): string
    {
        // The disk root is already storage/app/tenants, so the segment must NOT repeat it.
        if (function_exists('tenant')) {
            $tenantId = tenant('id');

            if (is_scalar($tenantId) && (string) $tenantId !== '') {
                return Str::slug((string) $tenantId);
            }
        }

        return 'central';
    }
}
