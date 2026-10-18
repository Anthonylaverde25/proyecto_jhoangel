<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * The three valid combinations of type and track_stock, as the operator chooses them. A service
 * with stock cannot even be expressed.
 */
enum ItemBehavior: string
{
    case STOCKED_ARTICLE = 'STOCKED_ARTICLE';
    case EXPENSE_ARTICLE = 'EXPENSE_ARTICLE';
    case SERVICE = 'SERVICE';

    public static function of(ItemType $type, bool $trackStock): self
    {
        if ($type === ItemType::SERVICE) {
            return self::SERVICE;
        }

        return $trackStock ? self::STOCKED_ARTICLE : self::EXPENSE_ARTICLE;
    }

    public function type(): ItemType
    {
        return $this === self::SERVICE ? ItemType::SERVICE : ItemType::ARTICLE;
    }

    public function tracksStock(): bool
    {
        return $this === self::STOCKED_ARTICLE;
    }
}
