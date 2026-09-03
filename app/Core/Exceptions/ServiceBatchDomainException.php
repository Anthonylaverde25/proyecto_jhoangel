<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

class ServiceBatchDomainException extends DomainException
{
    public static function inhomogeneousFemaleCategory(int $caravanId, string $actual, string $expected): self
    {
        return new self("La hembra [ID: {$caravanId}] pertenece a la categoría '{$actual}', pero este lote de servicio requiere estrictamente la categoría '{$expected}'.");
    }

    public static function inhomogeneousFemaleSubcategory(int $caravanId, string $actual, string $expected): self
    {
        return new self("La hembra [ID: {$caravanId}] tiene la subcategoría '{$actual}', pero este lote de servicio requiere estrictamente la subcategoría '{$expected}'.");
    }

    public static function invalidMaleCategory(int $caravanId, string $actual, string $expected): self
    {
        return new self("El reproductor [ID: {$caravanId}] pertenece a la categoría '{$actual}', pero se requiere estrictamente la categoría '{$expected}'.");
    }

    public static function infertileMaleAdmission(int $caravanId, string $identification): self
    {
        return new self("El toro [ID: {$caravanId}, Caravana: {$identification}] no puede ser admitido porque no está marcado como reproductor activo.");
    }

    public static function invalidAnimalSex(int $caravanId, string $expected, string $actual): self
    {
        return new self("El animal [ID: {$caravanId}] tiene sexo inválido '{$actual}', se esperaba '{$expected}'.");
    }

    public static function pregnantFemaleAdmission(int $caravanId, string $identification): self
    {
        return new self("La hembra [ID: {$caravanId}, Caravana: {$identification}] no puede ingresar al lote de servicio porque presenta una preñez activa en curso.");
    }

    public static function missingServiceConfiguration(int $batchId): self
    {
        return new self("El lote [ID: {$batchId}] está tipificado como SERVICE pero carece de la configuración de ServiceBatchDetail.");
    }

    public static function domainError(string $message): self
    {
        return new self($message);
    }
}
