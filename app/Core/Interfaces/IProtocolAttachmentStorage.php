<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

/**
 * ADR-6: sanitary evidence is written to a private per-tenant store. Keeping the contract in
 * the domain lets the storage move to S3 without touching a single use case.
 */
interface IProtocolAttachmentStorage
{
    /**
     * Persist a binary payload and return the relative path inside the store.
     */
    public function store(int $companyId, int $protocolId, string $contents, string $extension): string;

    /**
     * Compensating action for a failed ingestion: never leave orphan files behind (ADR-10).
     */
    public function delete(string $path): void;

    public function exists(string $path): bool;

    /**
     * Absolute filesystem path, used only by the guarded download endpoint.
     */
    public function absolutePath(string $path): string;
}
