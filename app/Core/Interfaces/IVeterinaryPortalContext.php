<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Enums\VeterinaryPortalAccessMode;

/**
 * Identifies who is operating the veterinary portal, regardless of how they reached it:
 * an in-system user holding the `veterinarian` role, or an external professional carrying
 * a temporary access token. Controllers and use cases depend on this contract only, so a
 * single portal implementation serves both entry points.
 */
interface IVeterinaryPortalContext
{
    public function isResolved(): bool;

    public function getAccessMode(): ?VeterinaryPortalAccessMode;

    public function getCompanyId(): int;

    public function getVeterinarianId(): int;

    public function getVeterinarianName(): string;

    public function getLicenseNumber(): string;

    /**
     * The system user behind the session, or null when the access is token based.
     */
    public function getUserId(): ?int;

    /**
     * Batch ids this session may operate on. A token can narrow the professional's
     * assignments down to a single batch.
     *
     * @return list<int>
     */
    public function getAllowedBatchIds(): array;

    public function canAccessBatch(int $batchId): bool;

    /**
     * ADR-16: extraction acts this session is narrowed to. Empty means "not narrowed" — the
     * session may reach any act belonging to the professional.
     *
     * @return list<int>
     */
    public function getAllowedProtocolIds(): array;

    public function canAccessAct(int $actId): bool;


    public function getAccessTokenId(): ?int;

    /**
     * A management user looking at a professional's portal reads everything and writes nothing.
     *
     * Signing, dispatching and reporting are acts somebody attests to; letting a manager perform
     * them in a professional's name would put that licence under a document they never saw.
     */
    public function isReadOnly(): bool;
}
