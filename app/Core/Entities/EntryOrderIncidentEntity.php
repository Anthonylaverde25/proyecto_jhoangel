<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\EntryOrderIncidentStatus;
use App\Core\Enums\EntryOrderIncidentType;
use App\Core\Exceptions\EntryOrderDomainException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Something of an entry order to settle with the provider. It never blocks the order nor changes
 * its status; it stays open until someone writes down what was agreed.
 */
final class EntryOrderIncidentEntity
{
    private bool $resolvedNow = false;

    /**
     * @param array<string, mixed> $metadata numbers and caravans behind the detail
     * @param ?string $dteNumber the DTE it refers to, to link it when that DTE is stored in the same save
     */
    public function __construct(
        private readonly ?int $id,
        private readonly EntryOrderIncidentType $type,
        private readonly string $detail,
        private readonly array $metadata,
        private EntryOrderIncidentStatus $status = EntryOrderIncidentStatus::OPEN,
        private readonly ?int $dteId = null,
        private readonly ?string $dteNumber = null,
        private ?string $resolution = null,
        private ?int $resolvedByUserId = null,
        private ?DateTimeInterface $resolvedAt = null,
        private readonly ?int $raisedByUserId = null,
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly ?string $raisedByUserName = null,
        private readonly ?string $resolvedByUserName = null
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function raise(EntryOrderIncidentType $type, string $detail, array $metadata, ?string $dteNumber, ?int $userId): self
    {
        return new self(
            id: null,
            type: $type,
            detail: $detail,
            metadata: $metadata,
            dteNumber: $dteNumber,
            raisedByUserId: $userId
        );
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function resolve(string $resolution, ?int $userId): void
    {
        if ($this->status === EntryOrderIncidentStatus::RESOLVED) {
            throw EntryOrderDomainException::invalid('La novedad ya está resuelta.', 'INCIDENT_ALREADY_RESOLVED');
        }

        if (trim($resolution) === '') {
            throw EntryOrderDomainException::invalid('Para resolver la novedad hay que escribir qué se acordó con el proveedor.', 'REASON_REQUIRED', 'resolution');
        }

        $this->status = EntryOrderIncidentStatus::RESOLVED;
        $this->resolution = trim($resolution);
        $this->resolvedByUserId = $userId;
        $this->resolvedAt = new DateTimeImmutable();
        $this->resolvedNow = true;
    }

    public function isOpen(): bool
    {
        return $this->status === EntryOrderIncidentStatus::OPEN;
    }

    public function isResolvedNow(): bool
    {
        return $this->resolvedNow;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): EntryOrderIncidentType
    {
        return $this->type;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getStatus(): EntryOrderIncidentStatus
    {
        return $this->status;
    }

    public function getDteId(): ?int
    {
        return $this->dteId;
    }

    public function getDteNumber(): ?string
    {
        return $this->dteNumber;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    public function getResolvedByUserId(): ?int
    {
        return $this->resolvedByUserId;
    }

    public function getResolvedAt(): ?DateTimeInterface
    {
        return $this->resolvedAt;
    }

    public function getRaisedByUserId(): ?int
    {
        return $this->raisedByUserId;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getRaisedByUserName(): ?string
    {
        return $this->raisedByUserName;
    }

    public function getResolvedByUserName(): ?string
    {
        return $this->resolvedByUserName;
    }
}
