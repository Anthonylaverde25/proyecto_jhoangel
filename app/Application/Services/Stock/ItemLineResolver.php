<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\ItemEntity;
use App\Core\Enums\ItemType;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IUnitOfMeasureRepository;
use App\Core\Services\QuantityConverter;
use App\Core\ValueObjects\Decimal;

/**
 * Resolves what a line of paper says: an active item of the company, a quantity greater than zero,
 * and a unit that converts to the item's unit or one of its active presentations (L1, L2, R1, R2).
 * Reports on the line's fields and returns null when the line cannot be used.
 */
final class ItemLineResolver
{
    /** @var array<int, ?ItemEntity> */
    private array $items = [];

    public function __construct(
        private readonly IItemRepository $itemRepository,
        private readonly IUnitOfMeasureRepository $units,
        private readonly QuantityConverter $converter,
    ) {
    }

    public function resolve(
        string $prefix,
        int $companyId,
        int $itemId,
        string $inputQuantity,
        ?int $inputUnitId,
        ?int $inputPresentationId,
        FieldErrorBag $errors,
        bool $stockedOnly,
    ): ?ResolvedLine {
        $item = $this->item($itemId, $companyId);
        if ($item === null) {
            $errors->add($this->field($prefix, 'item_id'), 'El ítem no existe en esta empresa.');

            return null;
        }
        if (!$item->isActive()) {
            $errors->add($this->field($prefix, 'item_id'), "{$item->getName()} está desactivado.");

            return null;
        }
        if ($stockedOnly && !$item->tracksStock()) {
            $errors->add($this->field($prefix, 'item_id'), $item->getType() === ItemType::SERVICE
                ? "{$item->getName()} es un servicio: no se mueve en el almacén."
                : "{$item->getName()} no lleva stock: se carga como gasto en el comprobante.");

            return null;
        }

        $quantity = Decimal::of($inputQuantity);
        if (!$quantity->isPositive()) {
            $errors->add($this->field($prefix, 'input_quantity'), 'La cantidad tiene que ser mayor que cero.');

            return null;
        }
        if (($inputUnitId === null) === ($inputPresentationId === null)) {
            $errors->add($this->field($prefix, 'input_unit_id'), 'Elegí la unidad o la presentación.');

            return null;
        }

        $itemUnit = $item->getUnit() ?? $this->units->findById($item->getUnitOfMeasureId(), $companyId);
        $presentation = null;
        $inputUnit = null;
        if ($inputPresentationId !== null) {
            foreach ($item->getPresentations() as $candidate) {
                if ($candidate->getId() === $inputPresentationId) {
                    $presentation = $candidate;
                }
            }
            if ($presentation === null || !$presentation->isActive()) {
                $errors->add($this->field($prefix, 'input_presentation_id'), $presentation === null
                    ? "La presentación no es de {$item->getName()}."
                    : "{$presentation->getName()} está desactivada.");

                return null;
            }
        } else {
            $inputUnit = $this->units->findById($inputUnitId, $companyId);
            if ($inputUnit === null) {
                $errors->add($this->field($prefix, 'input_unit_id'), 'La unidad no existe en esta empresa.');

                return null;
            }
        }

        $converted = $itemUnit === null ? null : $this->converter->toItemUnit($quantity, $itemUnit, $inputUnit, $presentation);
        if ($converted === null) {
            $errors->add($this->field($prefix, 'input_unit_id'), "{$inputUnit?->getName()} no se convierte a {$itemUnit?->getName()}: usá una presentación.");

            return null;
        }
        if (!$converted->isPositive()) {
            $errors->add($this->field($prefix, 'input_quantity'), "La cantidad es demasiado chica para {$itemUnit->getName()}.");

            return null;
        }

        return new ResolvedLine($item, $quantity, $converted, $inputUnitId, $inputPresentationId);
    }

    private function field(string $prefix, string $name): string
    {
        return $prefix === '' ? $name : "{$prefix}.{$name}";
    }

    public function item(int $itemId, int $companyId): ?ItemEntity
    {
        if (!array_key_exists($itemId, $this->items)) {
            $this->items[$itemId] = $this->itemRepository->findById($itemId, $companyId);
        }

        return $this->items[$itemId];
    }
}
