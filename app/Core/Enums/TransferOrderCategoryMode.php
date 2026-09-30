<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Whether the animals of a transfer order change category, and who decides the new one.
 *
 * Declared by whoever issues the order, never inferred: it is what the sheet is printed from.
 */
enum TransferOrderCategoryMode: string
{
    /** The category does not change. The sheet shows the current one, greyed, to find the animal. */
    case KEEP = 'KEEP';

    /** The new category is known at the desk: each line of the roll carries it and it is printed. */
    case DECLARED = 'DECLARED';

    /** The new category is decided at the chute: a blank C/S cell, where blank means "no change". */
    case AT_CHUTE = 'AT_CHUTE';

    public function label(): string
    {
        return match ($this) {
            self::KEEP => 'No cambia',
            self::DECLARED => 'Declarada en la orden',
            self::AT_CHUTE => 'Se decide en la manga',
        };
    }
}
