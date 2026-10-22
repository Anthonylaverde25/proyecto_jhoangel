<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A broken rule of the company's item catalog. Rendered as a 422 like every DomainException.
 */
final class ItemCatalogException extends DomainException
{
    public static function invalidCode(string $what): self
    {
        return new self("El código {$what} sólo admite letras, números y guion bajo.");
    }

    public static function nonPositiveFactor(): self
    {
        return new self('El factor tiene que ser mayor que cero.');
    }

    public static function baseNeedsFactorOne(): self
    {
        return new self('La unidad base de una dimensión equivale a 1.');
    }

    public static function unitInUse(string $unit, int $items): self
    {
        return new self("{$unit} ya es la unidad de {$items} ítem(s): su dimensión y su factor no se pueden cambiar. Desactivala y creá otra.");
    }

    public static function baseCannotBeDeactivated(string $unit, string $dimension): self
    {
        return new self("{$unit} es la base de {$dimension}: no se puede desactivar.");
    }

    public static function unitOfActiveItems(string $unit, int $items): self
    {
        return new self("{$unit} es la unidad de {$items} ítem(s) activo(s): no se puede desactivar.");
    }

    public static function missingBase(string $dimension): self
    {
        return new self("{$dimension} no tiene unidad base activa: no se puede crear una unidad convertible.");
    }

    public static function inactiveUnit(string $unit): self
    {
        return new self("La unidad {$unit} está desactivada.");
    }

    public static function inactiveCategory(string $category): self
    {
        return new self("La categoría {$category} está desactivada.");
    }

    public static function systemCategory(string $category): self
    {
        return new self("{$category} es una categoría del sistema: no se puede editar, mover ni desactivar. Sí admite subcategorías.");
    }

    public static function categoryCycle(string $category, string $parent): self
    {
        return new self("{$category} no puede quedar dentro de {$parent}: {$parent} ya está dentro de {$category}.");
    }

    public static function serviceWithStock(): self
    {
        return new self('Un servicio no lleva stock.');
    }

    public static function emptyName(): self
    {
        return new self('El nombre es obligatorio.');
    }

    public static function duplicateItemCode(string $code, string $name): self
    {
        return new self("Ya existe un ítem con el código {$code} ({$name}).");
    }

    public static function inactiveItem(string $item): self
    {
        return new self("{$item} está desactivado.");
    }

    public static function duplicatePresentation(string $item, string $presentation): self
    {
        return new self("{$item} ya tiene la presentación {$presentation}.");
    }

    public static function providerDoesNotSupplyItems(string $provider): self
    {
        return new self("{$provider} no está marcado como proveedor de artículos o servicios.");
    }

    public static function duplicateProvider(): self
    {
        return new self('El proveedor está dos veces.');
    }

    public static function severalPreferred(): self
    {
        return new self('Marcá un solo proveedor preferido.');
    }

    public static function invalidLeadTime(): self
    {
        return new self('La demora de entrega va de 0 a 365 días.');
    }

    public static function providerLinkedToItems(int $items): self
    {
        return new self("Está vinculado a {$items} ítem(s): desvinculalo primero para quitarle \"vende artículos o servicios\".");
    }

    public static function itemInUse(string $item): self
    {
        return new self("{$item} ya tiene movimientos: su unidad y su comportamiento no se pueden cambiar.");
    }

    public static function itemWithBalance(string $item, string $balance): self
    {
        return new self("{$item} tiene {$balance} en stock. Consumilo o ajustalo por conteo antes de desactivarlo.");
    }

    public static function presentationInUse(string $presentation): self
    {
        return new self("{$presentation} ya se usó en comprobantes: su nombre y su contenido no se pueden cambiar. Creá otra presentación.");
    }

    public static function unitInDocuments(string $unit, int $lines): self
    {
        return new self("{$unit} ya se usó en {$lines} renglón(es) de comprobantes: su dimensión y su factor no se pueden cambiar. Desactivala y creá otra.");
    }

    public static function providerHasFarms(int $farms): self
    {
        return new self("Tiene {$farms} campo(s) de origen: no se le puede quitar \"vende hacienda\".");
    }
}
