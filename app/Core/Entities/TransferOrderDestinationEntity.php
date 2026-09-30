<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * One destination a transfer order names: an existing batch, or one to be created.
 *
 * `key` is the normalised name, the same one a handwritten row of the paper is joined by.
 * The batch names beside the ids are read-model data, carried so the order can be shown
 * without a second round trip; they are never written back.
 */
final class TransferOrderDestinationEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $key,
        private readonly string $label,
        private readonly ?int $targetBatchId,
        private readonly ?string $newBatchName,
        private readonly ?int $newBatchTypeId,
        private readonly ?bool $isConfined,
        private ?int $resolvedBatchId = null,
        private readonly ?string $targetBatchName = null,
        private ?string $resolvedBatchName = null,
        private readonly ?string $newBatchTypeName = null,
        private readonly ?bool $targetBatchIsConfined = null
    ) {
    }

    /**
     * The management system of the batch that will receive the animals: what the new batch
     * declares, or what the existing batch already is. It is what the M cell prints.
     */
    public function effectiveIsConfined(): ?bool
    {
        return $this->isNewBatch() ? $this->isConfined : $this->targetBatchIsConfined;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getTargetBatchId(): ?int
    {
        return $this->targetBatchId;
    }

    public function getNewBatchName(): ?string
    {
        return $this->newBatchName;
    }

    public function getNewBatchTypeId(): ?int
    {
        return $this->newBatchTypeId;
    }

    public function isConfined(): ?bool
    {
        return $this->isConfined;
    }

    public function getResolvedBatchId(): ?int
    {
        return $this->resolvedBatchId;
    }

    public function getTargetBatchName(): ?string
    {
        return $this->targetBatchName;
    }

    public function getResolvedBatchName(): ?string
    {
        return $this->resolvedBatchName;
    }

    public function getNewBatchTypeName(): ?string
    {
        return $this->newBatchTypeName;
    }

    public function isNewBatch(): bool
    {
        return $this->targetBatchId === null;
    }

    /**
     * The batch that actually received the animals. Written once: a second round goes to the
     * batch the first one resolved to, it does not re-resolve.
     */
    public function resolveTo(int $batchId, string $batchName): void
    {
        if ($this->resolvedBatchId !== null) {
            return;
        }

        $this->resolvedBatchId = $batchId;
        $this->resolvedBatchName = $batchName;
    }
}
