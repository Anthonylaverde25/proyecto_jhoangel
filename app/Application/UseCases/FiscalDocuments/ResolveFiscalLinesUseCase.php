<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Application\DTOs\ResolvedFiscalLine;
use App\Core\Exceptions\FiscalReaderUnavailableException;
use App\Core\Interfaces\IFiscalItemMatcher;
use App\Core\Interfaces\IFiscalLineCatalogReader;
use App\Core\ValueObjects\FiscalCatalogItem;
use App\Core\ValueObjects\FiscalItemMatch;
use App\Core\ValueObjects\FiscalLineRead;
use App\Core\ValueObjects\ItemNameText;

/**
 * Resolves the item of each line read from a provider document (Delivery G, part 04 §2): first the
 * EXACT name, by code; the rest by meaning, through the matcher. Only HIGH fills the line; MEDIUM
 * leaves a candidate for the link dialog. A matcher failure never fails the reading: the lines come
 * back unresolved, with what the paper said.
 */
final class ResolveFiscalLinesUseCase
{
    public function __construct(
        private readonly IFiscalItemMatcher $matcher,
        private readonly IFiscalLineCatalogReader $catalog,
    ) {
    }

    /**
     * @param list<FiscalLineRead> $lines
     * @return list<ResolvedFiscalLine>
     */
    public function __invoke(array $lines, ?int $providerId, int $companyId): array
    {
        if ($lines === []) {
            return [];
        }

        $catalog = $this->catalog->activeItems($companyId);
        $vatRates = $this->catalog->vatRateIdsByCode();
        $vat = static fn (FiscalLineRead $line): ?int => $line->vatRateCode !== null ? ($vatRates[$line->vatRateCode] ?? null) : null;

        $resolved = [];
        $pending = [];
        foreach ($lines as $line) {
            $exact = $this->exactMatch($line, $catalog);
            if ($exact !== null) {
                $resolved[$line->position] = new ResolvedFiscalLine(
                    $line, ResolvedFiscalLine::SOURCE_EXACT, ResolvedFiscalLine::LEVEL_EXACT, $exact, null, null, $vat($line)
                );
            } else {
                $pending[] = $line;
            }
        }

        $pendingByPosition = $this->linesByPosition($pending);
        foreach ($this->matchByMeaning($pending, $catalog, $providerId, $companyId) as $position => [$match, $note]) {
            $line = $pendingByPosition[$position];
            $high = $match?->level === FiscalItemMatch::HIGH;
            $resolved[$position] = new ResolvedFiscalLine(
                $line,
                $high ? ResolvedFiscalLine::SOURCE_AI : ResolvedFiscalLine::SOURCE_NONE,
                $match?->level ?? FiscalItemMatch::NONE,
                $high ? $match->itemId : null,
                $match?->reason,
                $match?->level === FiscalItemMatch::MEDIUM ? $match->itemId : null,
                $vat($line),
                $note,
            );
        }

        return array_map(fn (FiscalLineRead $line) => $resolved[$line->position], $lines);
    }

    /**
     * The one active item whose name equals the description (G12); two homonyms are not exact.
     *
     * @param list<FiscalCatalogItem> $catalog
     */
    private function exactMatch(FiscalLineRead $line, array $catalog): ?int
    {
        $text = ItemNameText::of($line->description);
        $found = array_values(array_filter($catalog, fn (FiscalCatalogItem $item) => ItemNameText::of($item->name)->equals($text)));

        return count($found) === 1 ? $found[0]->id : null;
    }

    /**
     * @param list<FiscalLineRead> $lines
     * @param list<FiscalCatalogItem> $catalog
     * @return array<int, array{0: ?FiscalItemMatch, 1: ?string}> by position
     */
    private function matchByMeaning(array $lines, array $catalog, ?int $providerId, int $companyId): array
    {
        if ($lines === []) {
            return [];
        }

        $sent = array_flip(array_map(fn (FiscalCatalogItem $item) => $item->id, $catalog));
        $result = array_fill_keys(array_map(fn (FiscalLineRead $l) => $l->position, $lines), [null, null]);

        try {
            $providerItems = $providerId !== null ? $this->catalog->itemIdsOfProvider($providerId, $companyId) : [];
            $matches = $catalog === [] ? [] : $this->matcher->match($lines, $catalog, $providerItems, $companyId);
        } catch (FiscalReaderUnavailableException) {
            return array_map(fn () => [null, ResolvedFiscalLine::NOTE_MATCHER_UNAVAILABLE], $result);
        }

        foreach ($matches as $match) {
            if (!array_key_exists($match->position, $result)) {
                continue;
            }
            // The agent already checks this; the API is the authority on what belongs to the company.
            $known = $match->itemId !== null && isset($sent[$match->itemId]);
            $result[$match->position] = [
                $known ? $match : new FiscalItemMatch($match->position, null, FiscalItemMatch::NONE, $match->reason),
                null,
            ];
        }

        return $result;
    }

    /**
     * @param list<FiscalLineRead> $lines
     * @return array<int, FiscalLineRead>
     */
    private function linesByPosition(array $lines): array
    {
        $byPosition = [];
        foreach ($lines as $line) {
            $byPosition[$line->position] = $line;
        }

        return $byPosition;
    }
}
