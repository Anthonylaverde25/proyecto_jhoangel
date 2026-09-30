<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A strict registration received tags that already exist. Nothing was written: registering
 * over an existing animal would either overwrite it or silently transfer it between companies.
 */
final class CaravansAlreadyRegisteredException extends DomainException
{
    /**
     * @param array<int, array{identification: string, status: string}> $conflicts
     */
    private function __construct(string $message, private readonly array $conflicts)
    {
        parent::__construct($message);
    }

    /**
     * @param array<int, array{identification: string, status: string}> $conflicts
     */
    public static function forConflicts(array $conflicts): self
    {
        $identifications = array_map(static fn (array $c): string => $c['identification'], $conflicts);

        return new self(
            'Las siguientes caravanas ya están registradas y no se dio de alta ninguna: ' . implode(', ', $identifications) . '.',
            $conflicts
        );
    }

    /**
     * @return array<int, array{identification: string, status: string}>
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }
}
