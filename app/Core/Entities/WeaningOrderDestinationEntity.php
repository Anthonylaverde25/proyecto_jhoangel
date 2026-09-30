<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * One weaning batch a weaning order names: an existing one, or one to be created.
 *
 * `key` is the normalised name, the one a handwritten row of the paper is joined by. The batch
 * names beside the ids are read-model data, carried so the order can be shown without a second
 * round trip; they are never written back.
 */
final class WeaningOrderDestinationEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $key,
        private readonly string $label,
        private readonly ?int $targetBatchId,
        private readonly ?string $newBatchName,
        private readonly ?bool $isConfined,
        private ?int $resolvedBatchId = null,
        private readonly ?string $targetBatchName = null,
        private ?string $resolvedBatchName = null,
        private readonly ?bool $targetBatchIsConfined = null
    ) {
    }

    /**
     * The management system of the batch that will receive the calves: what the new batch
     * declares, or what the existing batch already is. It is what the sheet prints.
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

    public function isNewBatch(): bool
    {
        return $this->targetBatchId === null;
    }

    /**
     * The batch that actually received the calves. Written once: a second round goes to the
     * batch the first one resolved to.
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
