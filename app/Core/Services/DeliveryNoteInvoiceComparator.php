<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\FiscalDiscrepancyEntity;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\FiscalDocumentLineEntity;
use App\Core\Enums\DiscrepancyType;
use App\Core\ValueObjects\Decimal;

/**
 * Every difference between the delivery notes and the invoice converted from them (DC7, plan part 5
 * §5.6). Exact: no tolerance, not even for rounding. Prices and totals are compared net against net,
 * so only against an invoice that discriminates VAT (Q2).
 */
final class DeliveryNoteInvoiceComparator
{
    private const PRICE_SCALE = 6;

    /**
     * @param list<FiscalDocumentEntity> $deliveryNotes with their lines
     * @return list<FiscalDiscrepancyEntity>
     */
    public function compare(FiscalDocumentEntity $invoice, array $deliveryNotes, ?int $userId): array
    {
        $sources = [];
        foreach ($deliveryNotes as $note) {
            foreach ($note->getLines() as $line) {
                $sources[(int) $line->id] = ['note' => $note, 'line' => $line];
            }
        }

        $comparesPrice = $invoice->getType()->discriminatesVat;
        $found = [];
        $notedTotal = Decimal::zero();
        $invoicedTotal = Decimal::zero();
        $priced = false;
        $out = [];

        foreach ($invoice->getLines() as $line) {
            $source = $line->deliveryNoteLineId === null ? null : ($sources[$line->deliveryNoteLineId] ?? null);
            if ($source === null) {
                if ($line->tracksStock) {
                    $out[] = $this->make($invoice, null, $line, null, DiscrepancyType::EXTRA_LINE, null, $line->quantity,
                        "{$line->itemName} ({$this->quantity($line)}) no está en ningún remito: no se ingresó al stock.", $userId);
                }
                continue;
            }

            $note = $source['note'];
            $origin = $source['line'];
            $found[(int) $origin->id] = true;

            if (Decimal::of($origin->quantity)->compare(Decimal::of($line->quantity)) !== 0) {
                $out[] = $this->make($invoice, $note, $line, $origin, DiscrepancyType::QUANTITY, $origin->quantity, $line->quantity,
                    "{$line->itemName}: el {$note->label()} dice {$this->quantity($origin)} y la factura {$this->quantity($line)}.", $userId);
            }

            if ($comparesPrice && $note->isValued() === true) {
                $priced = true;
                $expected = $this->netUnitPrice($origin);
                $actual = $this->netUnitPrice($line);
                if ($expected->compare($actual) !== 0) {
                    $out[] = $this->make($invoice, $note, $line, $origin, DiscrepancyType::PRICE, $expected->toScale(self::PRICE_SCALE), $actual->toScale(self::PRICE_SCALE),
                        "{$line->itemName}: {$this->money($expected)} por {$line->itemUnitSymbol} en el {$note->label()}, {$this->money($actual)} en la factura.", $userId);
                }
                $notedTotal = $notedTotal->add(Decimal::of((string) $origin->lineAmount));
                $invoicedTotal = $invoicedTotal->add(Decimal::of((string) $line->lineAmount));
            }
        }

        foreach ($sources as $id => ['note' => $note, 'line' => $origin]) {
            if (!isset($found[$id])) {
                $out[] = $this->make($invoice, $note, null, $origin, DiscrepancyType::MISSING_LINE, $origin->quantity, null,
                    "{$origin->itemName} ({$this->quantity($origin)}) del {$note->label()} no está en la factura.", $userId);
            }
        }

        if ($priced && $notedTotal->compare($invoicedTotal) !== 0) {
            $out[] = $this->make($invoice, null, null, null, DiscrepancyType::TOTAL, $notedTotal->toScale(4), $invoicedTotal->toScale(4),
                "Los remitos suman {$this->money($notedTotal)} y la factura {$this->money($invoicedTotal)} por lo mismo.", $userId);
        }

        return $out;
    }

    /** Net price per unit of the item: the line amount without taxes over the quantity in the item's unit. */
    private function netUnitPrice(FiscalDocumentLineEntity $line): Decimal
    {
        return Decimal::of((string) $line->lineAmount)->div(Decimal::of($line->quantity))->round(self::PRICE_SCALE);
    }

    private function make(
        FiscalDocumentEntity $invoice,
        ?FiscalDocumentEntity $note,
        ?FiscalDocumentLineEntity $line,
        ?FiscalDocumentLineEntity $origin,
        DiscrepancyType $type,
        ?string $expected,
        ?string $actual,
        string $detail,
        ?int $userId,
    ): FiscalDiscrepancyEntity {
        return new FiscalDiscrepancyEntity(
            null,
            $invoice->getCompanyId(),
            (int) $invoice->getId(),
            $note?->getId(),
            $line?->id,
            $origin?->id,
            $type === DiscrepancyType::TOTAL ? null : ($line?->itemId ?? $origin?->itemId),
            $type,
            $expected,
            $actual,
            $detail,
            raisedByUserId: $userId,
        );
    }

    /** "10 Frasco × 500 ml", as typed on the paper. */
    private function quantity(FiscalDocumentLineEntity $line): string
    {
        return $this->number(Decimal::of($line->inputQuantity), 4) . ' ' . ($line->inputLabel ?? $line->itemUnitSymbol);
    }

    /** "$ 12.500", "$ 0,05". */
    private function money(Decimal $amount): string
    {
        return '$ ' . $this->number($amount, 2);
    }

    private function number(Decimal $value, int $decimals): string
    {
        $formatted = number_format((float) $value->toScale($decimals), $decimals, ',', '.');

        return str_contains($formatted, ',') ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }
}
