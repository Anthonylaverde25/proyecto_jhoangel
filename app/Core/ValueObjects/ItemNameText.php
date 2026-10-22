<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * The comparable form of an item name or a line description, for the EXACT level (Delivery G, G12):
 * lower case, no accents, punctuation as spaces, spaces collapsed. "SAL MINERAL." equals
 * "Sal mineral"; "Ivermectina 1% x 500 ml" does not equal "Ivermectina 1%".
 */
final readonly class ItemNameText
{
    private function __construct(public string $value)
    {
    }

    public static function of(string $text): self
    {
        $plain = strtr(mb_strtolower($text), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);
        $spaced = (string) preg_replace('/[^0-9a-z]+/', ' ', $plain);

        return new self(trim((string) preg_replace('/\s+/', ' ', $spaced)));
    }

    public function equals(self $other): bool
    {
        return $this->value !== '' && $this->value === $other->value;
    }
}
