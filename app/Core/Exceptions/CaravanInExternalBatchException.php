<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class CaravanInExternalBatchException extends DomainException
{
    /**
     * @param array<int, array{id: int, identification: string, batch_name: string}> $invalidCaravans
     */
    public static function forCaravans(array $invalidCaravans): self
    {
        $details = array_map(
            fn($c) => "Caravana '{$c['identification']}' en Lote Externo '{$c['batch_name']}'",
            $invalidCaravans
        );

        return new self(
            "Los siguientes animales pertenecen a lotes externos y deben ser asignados a un lote propio antes de iniciar este proceso operativo: " . implode(', ', $details) . "."
        );
    }
}
