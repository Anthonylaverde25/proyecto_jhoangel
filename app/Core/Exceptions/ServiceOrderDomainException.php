<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

class ServiceOrderDomainException extends DomainException
{
    /** Machine-readable reason, so the client can react (e.g. highlight the pending females). */
    private ?string $reasonCode = null;

    /** @var array<string, mixed> */
    private array $context = [];

    public static function invalidStateTransition(string $from, string $to): self
    {
        return new self("Invalid state transition from {$from} to {$to}");
    }

    public static function activeOrderConflict(string $type, int $id): self
    {
        return new self("The {$type} with ID {$id} is already assigned to another active service order");
    }

    public static function invalidAnimalSex(int $id, string $expected, string $actual): self
    {
        return new self("Animal with ID {$id} is not a {$expected}. Sex is {$actual}");
    }

    public static function domainError(string $message): self
    {
        return new self($message);
    }

    /**
     * The order cannot be closed while a female has no final verdict.
     *
     * @param int[] $femaleIds
     */
    public static function pendingDiagnoses(array $femaleIds): self
    {
        $count = count($femaleIds);
        $exception = new self(
            $count === 1
                ? 'No se puede cerrar la orden: falta el diagnóstico de 1 vientre.'
                : "No se puede cerrar la orden: falta el diagnóstico de {$count} vientres."
        );
        $exception->reasonCode = 'PENDING_DIAGNOSES';
        $exception->context = ['pending_female_ids' => array_values($femaleIds)];

        return $exception;
    }

    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
